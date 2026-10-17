<?php

namespace App\Services\Ai;

use App\Exceptions\AiUnavailableException;
use App\Services\Ai\Contracts\AiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gateway to an OpenAI-compatible endpoint (spec §7.8); named after the
 * contract it speaks, never after a vendor.
 *
 * Chat posts to `/v1/chat/completions` with `stream:false` and
 * `Accept: application/json`; without both the gateway answers with SSE.
 * Embeddings post to `/v1/embeddings` in batches of at most 64 inputs.
 *
 * Every failure is mapped from the (status, body) pair of §7.8 to a stable
 * `AiUnavailableException::CODE_*`. An unrecognised pair is never guessed:
 * it becomes `EXTERNAL_ERROR` (unmapped) or `CONTRACT_VIOLATION` (the 200
 * body does not look like an OpenAI response).
 *
 * The Bearer key is never logged and never put into an exception message.
 */
class OpenAiCompatibleClient implements AiClient
{
    /**
     * Provider maximum accepted per `/v1/embeddings` request.
     */
    public const EMBED_BATCH_SIZE = 64;

    /**
     * Marker bodies from §7.8 used to distinguish an otherwise identical
     * status; compared case-insensitively.
     */
    private const BODY_INVALID_MODEL_FORMAT = 'invalid model format';

    private const BODY_EMBED_NOT_SUPPORTED = 'does not support embeddings';

    private const BODY_NO_ACTIVE_CREDENTIALS = 'no active credentials';

    /**
     * @param  string|null  $baseUrl  Gateway root, e.g. `https://router.local`.
     * @param  string|null  $apiKey  Bearer token; never logged.
     * @param  string|null  $chatModel  Model id used by chat().
     * @param  string|null  $embedModel  Model id used by embed().
     * @param  int  $timeout  Request timeout in seconds.
     */
    public function __construct(
        public readonly ?string $baseUrl = null,
        public readonly ?string $apiKey = null,
        public readonly ?string $chatModel = null,
        public readonly ?string $embedModel = null,
        public readonly int $timeout = 90,
    ) {}

    /**
     * Build the client from `config('ai.openai_compatible.*')`, the only
     * allowed source of base URL, models and credentials.
     */
    public static function fromConfig(): self
    {
        $config = config('ai.openai_compatible', []);

        return new self(
            baseUrl: $config['base_url'] ?? null,
            apiKey: $config['api_key'] ?? null,
            chatModel: $config['chat_model'] ?? null,
            embedModel: $config['embed_model'] ?? null,
            timeout: (int) ($config['timeout'] ?? 90),
        );
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string}
     *
     * @throws AiUnavailableException
     */
    public function chat(array $messages): array
    {
        $this->assertConfigured();

        $response = $this->send('/v1/chat/completions', [
            'model' => $this->chatModel,
            'messages' => array_values($messages),
            'stream' => false,
        ]);

        $this->assertJsonContentType($response);
        $this->assertSuccessfulStatus($response);

        $payload = $this->decode($response);
        $choice = $payload['choices'][0] ?? null;
        $content = is_array($choice) ? ($choice['message']['content'] ?? null) : null;

        if (! is_string($content)) {
            throw AiUnavailableException::make(
                AiUnavailableException::CODE_CONTRACT_VIOLATION,
                'Respons chat tidak memuat choices[0].message.content.',
            );
        }

        return ['content' => $content];
    }

    /**
     * Embed every text in the list, in order, in batches of 64.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     *
     * @throws AiUnavailableException
     */
    public function embed(array $texts): array
    {
        $this->assertConfigured();

        $vectors = [];

        foreach (array_chunk(array_values($texts), self::EMBED_BATCH_SIZE) as $batch) {
            $response = $this->send('/v1/embeddings', [
                'model' => $this->embedModel,
                'input' => $batch,
            ]);

            $this->assertJsonContentType($response);
            $this->assertSuccessfulStatus($response);

            foreach ($this->extractEmbeddings($this->decode($response), count($batch)) as $vector) {
                $vectors[] = $vector;
            }
        }

        return $vectors;
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws AiUnavailableException
     */
    private function send(string $path, array $body): Response
    {
        try {
            return $this->request()->post(rtrim((string) $this->baseUrl, '/').$path, $body);
        } catch (ConnectionException $e) {
            throw $this->logAndBuild(
                AiUnavailableException::CODE_EXTERNAL_UNAVAILABLE,
                'Koneksi ke provider AI gagal.',
                context: ['path' => $path],
                previous: $e,
            );
        }
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken((string) $this->apiKey)
            ->timeout($this->timeout)
            ->connectTimeout(min($this->timeout, 10));
    }

    /**
     * A streamed answer means the request lost either `stream:false` or the
     * `Accept: application/json` header (spec E34); never parse it as JSON.
     *
     * @throws AiUnavailableException
     */
    private function assertJsonContentType(Response $response): void
    {
        if (str_starts_with(strtolower(trim($response->header('Content-Type'))), 'text/event-stream')) {
            throw $this->logAndBuild(
                AiUnavailableException::CODE_SSE_NOT_EXPECTED,
                'Provider membalas text/event-stream, bukan JSON.',
                context: ['status' => $response->status()],
            );
        }
    }

    /**
     * Map the §7.8 (status, body) table to an internal error code.
     *
     * @throws AiUnavailableException
     */
    private function assertSuccessfulStatus(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $body = $response->body();
        $lowerBody = strtolower($body);
        $retryAfter = $this->retryAfter($response);

        $code = match (true) {
            $status === 401 => AiUnavailableException::CODE_EXTERNAL_AUTH,
            $status === 403 => AiUnavailableException::CODE_EXTERNAL_FORBIDDEN,
            $status === 400 && str_contains($lowerBody, self::BODY_EMBED_NOT_SUPPORTED) => AiUnavailableException::CODE_EMBED_NOT_SUPPORTED,
            $status === 400 && str_contains($lowerBody, self::BODY_INVALID_MODEL_FORMAT) => AiUnavailableException::CODE_INVALID_MODEL,
            $status === 404 && str_contains($lowerBody, self::BODY_NO_ACTIVE_CREDENTIALS) => AiUnavailableException::CODE_PROVIDER_NOT_CONFIGURED,
            $status === 429 => AiUnavailableException::CODE_EXTERNAL_RATE_LIMIT,
            $status === 503 => AiUnavailableException::CODE_EXTERNAL_UNAVAILABLE,
            default => AiUnavailableException::CODE_EXTERNAL_ERROR,
        };

        throw $this->logAndBuild(
            $code,
            $this->statusMessage($status),
            $retryAfter,
            ['status' => $status],
        );
    }

    private function statusMessage(int $status): string
    {
        return match ($status) {
            401 => 'Kredensial provider AI ditolak.',
            403 => 'Kredensial provider AI tidak berizin untuk model ini.',
            400 => 'Permintaan ke provider AI tidak valid.',
            404 => 'Provider AI belum memiliki kredensial aktif.',
            429 => 'Rate limit provider AI tercapai.',
            503 => 'Provider AI sedang tidak tersedia.',
            default => 'Provider AI membalas status '.$status.'.',
        };
    }

    /**
     * Parse `retry-after` as seconds; an HTTP-date is converted to the
     * remaining seconds. Missing / unparsable → null.
     */
    private function retryAfter(Response $response): ?int
    {
        $header = trim($response->header('retry-after'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - now()->getTimestamp());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiUnavailableException
     */
    private function decode(Response $response): array
    {
        $payload = json_decode($response->body(), true);

        if (! is_array($payload)) {
            throw $this->logAndBuild(
                AiUnavailableException::CODE_CONTRACT_VIOLATION,
                'Body respons provider bukan objek JSON.',
                context: ['status' => $response->status()],
            );
        }

        return $payload;
    }

    /**
     * Read `data[].embedding` in provider order and verify the count.
     *
     * @param  array<string, mixed>  $payload
     * @return list<list<float>>
     *
     * @throws AiUnavailableException
     */
    private function extractEmbeddings(array $payload, int $expected): array
    {
        $items = $payload['data'] ?? null;

        if (! is_array($items) || count($items) !== $expected) {
            throw $this->logAndBuild(
                AiUnavailableException::CODE_CONTRACT_VIOLATION,
                'Respons embedding tidak memuat data sesuai jumlah input.',
            );
        }

        if (array_filter($items, static fn ($item): bool => isset($item['index']))) {
            usort($items, static fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
        }

        $vectors = [];

        foreach ($items as $item) {
            $vector = is_array($item) ? ($item['embedding'] ?? null) : null;

            if (! is_array($vector) || $vector === []) {
                throw $this->logAndBuild(
                    AiUnavailableException::CODE_CONTRACT_VIOLATION,
                    'Respons embedding tidak memuat data[].embedding.',
                );
            }

            $vectors[] = array_map(static fn ($value): float => (float) $value, array_values($vector));
        }

        return $vectors;
    }

    /**
     * @throws AiUnavailableException
     */
    private function assertConfigured(): void
    {
        foreach ([
            'base_url' => $this->baseUrl,
            'api_key' => $this->apiKey,
            'chat_model' => $this->chatModel,
            'embed_model' => $this->embedModel,
        ] as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                throw AiUnavailableException::make(
                    AiUnavailableException::CODE_PROVIDER_NOT_CONFIGURED,
                    'Konfigurasi provider AI tidak lengkap: ai.openai_compatible.'.$key.'.',
                );
            }
        }
    }

    /**
     * Log the failure with status/context only (never the api key, never the
     * provider body) and build the matching exception.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws AiUnavailableException
     */
    private function logAndBuild(string $code, string $message, ?int $retryAfter = null, array $context = [], ?Throwable $previous = null): AiUnavailableException
    {
        Log::warning('ai.provider_failed', array_merge($context, [
            'error_code' => $code,
            'retry_after' => $retryAfter,
        ]));

        return AiUnavailableException::make($code, $message, $retryAfter, $context);
    }
}
