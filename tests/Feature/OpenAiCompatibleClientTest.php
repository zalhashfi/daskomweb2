<?php

namespace Tests\Feature;

use App\Exceptions\AiUnavailableException;
use App\Models\AiConfig;
use App\Services\Ai\AiClientManager;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T20c — `openai_compatible` driver (gateway contract §7.8).
 *
 * Covers the success shape of chat/embed, the request envelope
 * (`stream:false`, `Accept: application/json`, Bearer), the full
 * (status, body) → errorCode mapping, embedding batches of 64, the manager
 * resolution, and that the api key never leaks into logs or exceptions.
 */
class OpenAiCompatibleClientTest extends TestCase
{
    private const BASE_URL = 'https://gateway.test';

    private const API_KEY = 'sk-test-secret-5f3a9c';

    private const CHAT_MODEL = 'google/gemini-flash';

    private const EMBED_MODEL = 'google/gemini-embedding-001';

    private function chatUrl(): string
    {
        return self::BASE_URL.'/v1/chat/completions';
    }

    private function embedUrl(): string
    {
        return self::BASE_URL.'/v1/embeddings';
    }

    private function client(): OpenAiCompatibleClient
    {
        return new OpenAiCompatibleClient(
            baseUrl: self::BASE_URL,
            apiKey: self::API_KEY,
            chatModel: self::CHAT_MODEL,
            embedModel: self::EMBED_MODEL,
            timeout: 5,
        );
    }

    private function messages(): array
    {
        return [
            ['role' => 'system', 'content' => 'Anda penilai.'],
            ['role' => 'user', 'content' => 'Nilai jawaban ini.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chatCompletion(string $content): array
    {
        return [
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'model' => self::CHAT_MODEL,
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ],
            ],
        ];
    }

    /**
     * @param  list<list<float>>  $vectors
     * @return array<string, mixed>
     */
    private function embeddings(array $vectors): array
    {
        $data = [];

        foreach ($vectors as $index => $vector) {
            $data[] = ['object' => 'embedding', 'index' => $index, 'embedding' => $vector];
        }

        return ['object' => 'list', 'model' => self::EMBED_MODEL, 'data' => $data];
    }

    private function assertErrorCode(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail('AiUnavailableException '.$code.' tidak dilempar.');
        } catch (AiUnavailableException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    /**
     * Swap in a spy logger that keeps every entry in `$logs`; `Log::spy()`
     * alone detaches the real logger with nowhere to inspect the calls.
     *
     * @param  list<array{0: string, 1: array<string, mixed>}>  $logs
     */
    private function spyOnLog(array &$logs): void
    {
        Log::spy();

        $receiver = \Mockery::mock(\Psr\Log\LoggerInterface::class)->shouldIgnoreMissing();
        $receiver->shouldReceive('warning', 'error', 'critical', 'info', 'debug', 'alert', 'emergency')
            ->andReturnUsing(function (string $message, array $context = []) use (&$logs): void {
                $logs[] = [$message, $context];
            });

        Log::swap($receiver);
    }

    public function test_chat_returns_assistant_content(): void
    {
        Http::fake([$this->chatUrl() => Http::response($this->chatCompletion('Skor 82.'), 200)]);

        $result = $this->client()->chat($this->messages());

        $this->assertSame(['content' => 'Skor 82.'], $result);
        $this->assertSame('Skor 82.', $result['content']);
    }

    public function test_chat_request_has_stream_false_accept_json_and_bearer(): void
    {
        Http::fake([$this->chatUrl() => Http::response($this->chatCompletion('ok'), 200)]);

        $this->client()->chat($this->messages());

        Http::assertSent(function (Request $request): bool {
            return $request->url() === $this->chatUrl()
                && $request->method() === 'POST'
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY)
                && $request->data()['stream'] === false
                && $request->data()['model'] === self::CHAT_MODEL
                && $request->data()['messages'] === $this->messages();
        });

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $this->rawBodyContains($request, '"stream":false'));
    }

    public function test_embed_request_has_model_input_and_bearer(): void
    {
        Http::fake([$this->embedUrl() => Http::response($this->embeddings([[0.1, 0.2]]), 200)]);

        $this->client()->embed(['halo']);

        Http::assertSent(fn (Request $request): bool => $request->url() === $this->embedUrl()
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY)
            && $request->data() === ['model' => self::EMBED_MODEL, 'input' => ['halo']]);
    }

    public function test_embed_returns_float_vectors_in_input_order(): void
    {
        Http::fake([$this->embedUrl() => Http::response($this->embeddings([
            [0.5, 1],
            [2.5, 3],
        ]), 200)]);

        $vectors = $this->client()->embed(['satu', 'dua']);

        $this->assertSame([[0.5, 1.0], [2.5, 3.0]], $vectors);
    }

    public function test_embed_batches_130_texts_into_64_64_2(): void
    {
        Http::fake([$this->embedUrl() => function (Request $request) {
            $vectors = array_fill(0, count($request->data()['input']), [0.25]);

            return Http::response($this->embeddings($vectors), 200);
        }]);

        $texts = array_map(fn (int $i): string => 'teks-'.$i, range(1, 130));

        $vectors = $this->client()->embed($texts);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => count($request->data()['input']) === 64, 1);
        Http::assertSent(fn (Request $request): bool => count($request->data()['input']) === 2, 1);
        $this->assertCount(130, $vectors);
        $this->assertSame([0.25], $vectors[129]);
    }

    public function test_401_maps_to_external_auth(): void
    {
        Http::fake([$this->chatUrl() => Http::response(['error' => ['message' => 'unauthorized']], 401)]);

        $this->assertErrorCode(
            AiUnavailableException::CODE_EXTERNAL_AUTH,
            fn () => $this->client()->chat($this->messages()),
        );
    }

    public function test_403_maps_to_external_forbidden(): void
    {
        Http::fake([$this->chatUrl() => Http::response(['error' => ['message' => 'forbidden']], 403)]);

        $this->assertErrorCode(
            AiUnavailableException::CODE_EXTERNAL_FORBIDDEN,
            fn () => $this->client()->chat($this->messages()),
        );
    }

    public function test_400_invalid_model_format_maps_to_invalid_model(): void
    {
        Http::fake([$this->chatUrl() => Http::response([
            'error' => ['message' => 'Invalid model format: expected provider/model'],
        ], 400)]);

        $this->assertErrorCode(
            AiUnavailableException::CODE_INVALID_MODEL,
            fn () => $this->client()->chat($this->messages()),
        );
    }

    public function test_400_does_not_support_embeddings_maps_to_embed_not_supported(): void
    {
        Http::fake([$this->embedUrl() => Http::response([
            'error' => ['message' => 'Model google/gemini-flash does not support embeddings'],
        ], 400)]);

        $this->assertErrorCode(
            AiUnavailableException::CODE_EMBED_NOT_SUPPORTED,
            fn () => $this->client()->embed(['halo']),
        );
    }

    public function test_404_no_active_credentials_maps_to_provider_not_configured(): void
    {
        Http::fake([$this->chatUrl() => Http::response([
            'error' => ['message' => 'No active credentials for provider google'],
        ], 404)]);

        $this->assertErrorCode(
            AiUnavailableException::CODE_PROVIDER_NOT_CONFIGURED,
            fn () => $this->client()->chat($this->messages()),
        );
    }

    public function test_429_maps_to_external_rate_limit_with_retry_after(): void
    {
        Http::fake([$this->chatUrl() => Http::response(
            ['error' => ['message' => 'rate limited']],
            429,
            ['retry-after' => '17'],
        )]);

        try {
            $this->client()->chat($this->messages());
            $this->fail('AiUnavailableException tidak dilempar.');
        } catch (AiUnavailableException $e) {
            $this->assertSame(AiUnavailableException::CODE_EXTERNAL_RATE_LIMIT, $e->errorCode());
            $this->assertSame(17, $e->retryAfter);
        }
    }

    public function test_503_maps_to_external_unavailable_and_carries_retry_after(): void
    {
        Http::fake([$this->chatUrl() => Http::response(
            ['error' => ['message' => 'All accounts unavailable for provider google']],
            503,
            ['retry-after' => '42'],
        )]);

        try {
            $this->client()->chat($this->messages());
            $this->fail('AiUnavailableException tidak dilempar.');
        } catch (AiUnavailableException $e) {
            $this->assertSame(AiUnavailableException::CODE_EXTERNAL_UNAVAILABLE, $e->errorCode());
            $this->assertSame(42, $e->retryAfter);
        }
    }

    public function test_5xx_without_retry_after_maps_to_external_error(): void
    {
        Http::fake([$this->chatUrl() => Http::response('upstream boom', 500)]);

        try {
            $this->client()->chat($this->messages());
            $this->fail('AiUnavailableException tidak dilempar.');
        } catch (AiUnavailableException $e) {
            $this->assertSame(AiUnavailableException::CODE_EXTERNAL_ERROR, $e->errorCode());
            $this->assertNull($e->retryAfter);
        }
    }

    public function test_unrecognised_pairs_map_to_external_error(): void
    {
        $cases = [
            [404, 'model not found'],
            [400, 'some other validation problem'],
            [418, 'teapot'],
            [422, 'unprocessable'],
        ];

        foreach ($cases as [$status, $message]) {
            Http::fake([$this->chatUrl() => Http::response(['error' => ['message' => $message]], $status)]);

            try {
                $this->client()->chat($this->messages());
                $this->fail('AiUnavailableException tidak dilempar untuk status '.$status.'.');
            } catch (AiUnavailableException $e) {
                $this->assertSame(AiUnavailableException::CODE_EXTERNAL_ERROR, $e->errorCode(), 'status '.$status);
            }
        }
    }

    public function test_event_stream_response_maps_to_sse_not_expected(): void
    {
        Http::fake([$this->chatUrl() => Http::response(
            "data: {\"choices\":[{\"delta\":{\"content\":\"ha\"}}]}\n\ndata: [DONE]\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        )]);

        $this->assertErrorCode(
            AiUnavailableException::CODE_SSE_NOT_EXPECTED,
            fn () => $this->client()->chat($this->messages()),
        );
    }

    public function test_chat_body_without_choices_maps_to_contract_violation(): void
    {
        Http::fake([$this->chatUrl() => Http::response(['id' => 'x', 'object' => 'chat.completion'], 200)]);

        $this->assertErrorCode(
            AiUnavailableException::CODE_CONTRACT_VIOLATION,
            fn () => $this->client()->chat($this->messages()),
        );
    }

    public function test_chat_body_with_empty_choices_maps_to_contract_violation(): void
    {
        Http::fake([$this->chatUrl() => Http::response(['choices' => []], 200)]);

        $this->assertErrorCode(
            AiUnavailableException::CODE_CONTRACT_VIOLATION,
            fn () => $this->client()->chat($this->messages()),
        );
    }

    public function test_chat_body_without_string_content_maps_to_contract_violation(): void
    {
        Http::fake([$this->chatUrl() => Http::response([
            'choices' => [['message' => ['role' => 'assistant']]],
        ], 200)]);

        $this->assertErrorCode(
            AiUnavailableException::CODE_CONTRACT_VIOLATION,
            fn () => $this->client()->chat($this->messages()),
        );
    }

    public function test_embedding_body_without_data_maps_to_contract_violation(): void
    {
        Http::fake([$this->embedUrl() => Http::response(['object' => 'list'], 200)]);

        $this->assertErrorCode(
            AiUnavailableException::CODE_CONTRACT_VIOLATION,
            fn () => $this->client()->embed(['halo']),
        );
    }

    public function test_connection_failure_maps_to_external_unavailable(): void
    {
        Http::fake([$this->chatUrl() => Http::failedConnection()]);

        $this->assertErrorCode(
            AiUnavailableException::CODE_EXTERNAL_UNAVAILABLE,
            fn () => $this->client()->chat($this->messages()),
        );
    }

    public function test_manager_resolves_configured_client_for_chat_and_embed(): void
    {
        $config = new AiConfig(['driver' => 'openai_compatible', 'embed_driver' => 'openai_compatible']);
        $manager = app(AiClientManager::class);

        $chat = $manager->forChat($config);
        $embed = $manager->forEmbed($config);

        $this->assertInstanceOf(OpenAiCompatibleClient::class, $chat);
        $this->assertInstanceOf(OpenAiCompatibleClient::class, $embed);
        $this->assertInstanceOf(AiClient::class, $chat);

        $this->assertSame(config('ai.openai_compatible.base_url'), $chat->baseUrl);
        $this->assertSame(config('ai.openai_compatible.chat_model'), $chat->chatModel);
        $this->assertSame(config('ai.openai_compatible.embed_model'), $embed->embedModel);
    }

    public function test_missing_configuration_maps_to_provider_not_configured(): void
    {
        Http::fake();

        $client = new OpenAiCompatibleClient(baseUrl: self::BASE_URL, chatModel: self::CHAT_MODEL, embedModel: self::EMBED_MODEL);

        $this->assertErrorCode(
            AiUnavailableException::CODE_PROVIDER_NOT_CONFIGURED,
            fn () => $client->chat($this->messages()),
        );

        Http::assertNothingSent();
    }

    public function test_api_key_is_absent_from_logs_and_exception_messages(): void
    {
        $logs = [];
        $this->spyOnLog($logs);

        $responses = [
            [401, [], 'unauthorized'],
            [403, [], 'forbidden'],
            [400, [], 'Invalid model format'],
            [404, [], 'No active credentials for provider google'],
            [429, ['retry-after' => '9'], 'rate limited'],
            [503, ['retry-after' => '3'], 'All accounts unavailable'],
            [500, [], 'boom'],
        ];

        foreach ($responses as [$status, $headers, $message]) {
            Http::fake([$this->chatUrl() => Http::response(
                ['error' => ['message' => $message, 'key' => self::API_KEY]],
                $status,
                $headers,
            )]);

            try {
                $this->client()->chat($this->messages());
                $this->fail('AiUnavailableException tidak dilempar untuk status '.$status.'.');
            } catch (AiUnavailableException $e) {
                $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
            }
        }

        Http::fake([$this->chatUrl() => Http::response(
            "data: [DONE]\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        )]);

        try {
            $this->client()->chat($this->messages());
            $this->fail('AiUnavailableException SSE tidak dilempar.');
        } catch (AiUnavailableException $e) {
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }

        $this->assertNotSame([], $logs, 'Kegagalan provider harus tercatat di log.');
        $this->assertStringNotContainsString(self::API_KEY, json_encode($logs, JSON_THROW_ON_ERROR));
    }

    public function test_success_does_not_log_the_api_key(): void
    {
        $logs = [];
        $this->spyOnLog($logs);

        Http::fake([$this->chatUrl() => Http::response($this->chatCompletion('ok'), 200)]);

        $this->client()->chat($this->messages());

        $this->assertStringNotContainsString(self::API_KEY, json_encode($logs, JSON_THROW_ON_ERROR));
    }

    public function test_source_does_not_name_the_gateway_product(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $this->assertStringNotContainsStringIgnoringCase(
                '9router',
                (string) file_get_contents($file->getPathname()),
                $file->getPathname().' tidak boleh menyebut nama produk gateway.',
            );
        }
    }

    private function rawBodyContains(Request $request, string $needle): bool
    {
        return str_contains((string) $request->body(), $needle);
    }
}
