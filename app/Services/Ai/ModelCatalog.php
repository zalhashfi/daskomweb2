<?php

namespace App\Services\Ai;

use App\Exceptions\AiUnavailableException;
use App\Exceptions\ModelCatalogException;
use App\Models\AiProviderCredential;
use App\Support\Ai\ProviderEndpoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Read-only view of the provider model catalogue (`GET /v1/models`, spec
 * §5.4.0a). It is the only discovery endpoint ever called: `/v1/models/info`
 * and friends are never used.
 *
 * The catalogue is cached for 24 hours, so repeated validations inside the
 * TTL never reach the provider again.
 *
 * Embedding dimensions are deliberately absent: the provider does not expose
 * them consistently, so the configured `ai.embed_profiles.*.dimension` is the
 * only source (verified lazily against the first returned vector length).
 */
class ModelCatalog
{
    public const CACHE_TTL_HOURS = 24;

    public const CACHE_KEY = 'ai:model_catalog';

    /**
     * @param  string|null  $baseUrl  Gateway root, e.g. `https://router.local`.
     * @param  string|null  $apiKey  Bearer token; never logged.
     * @param  int  $timeout  Request timeout in seconds.
     */
    public function __construct(
        public readonly ?string $baseUrl = null,
        public readonly ?string $apiKey = null,
        public readonly int $timeout = 90,
    ) {}

    /**
     * Build the catalogue from the active database credential first, falling
     * back to `config('ai.openai_compatible.*')` for the base URL, api key
     * and timeout.
     */
    public static function fromConfig(): self
    {
        $config = config('ai.openai_compatible', []);

        $override = AiProviderCredential::active();

        return new self(
            baseUrl: $override?->resolvedBaseUrl() ?: (string) ($config['base_url'] ?? ''),
            apiKey: $override?->resolvedApiKey() ?: (string) ($config['api_key'] ?? ''),
            timeout: (int) ($config['timeout'] ?? 90),
        );
    }

    /**
     * Assert the id exists in the catalogue and is not a Combo.
     *
     * @throws ModelCatalogException MODEL_NOT_FOUND / COMBO_NOT_ALLOWED
     * @throws AiUnavailableException when the catalogue itself is unreachable
     */
    public function assertChatModel(string $id): void
    {
        $models = $this->catalog();

        $entry = $models[$id] ?? null;

        if ($entry === null) {
            throw ModelCatalogException::modelNotFound($id);
        }

        if ($entry['owned_by'] === 'combo') {
            throw ModelCatalogException::comboNotAllowed($id);
        }
    }

    /**
     * The catalogue as `id => ['id' => ..., 'owned_by' => ...]`, cached for 24h
     * under a key that includes the credential fingerprint, so a base URL or
     * api key changed from the UI never serves the previous provider's list.
     *
     * @return array<string, array{id: string, owned_by: string|null}>
     *
     * @throws AiUnavailableException
     */
    public function catalog(): array
    {
        return Cache::remember($this->cacheKey(), now()->addHours(self::CACHE_TTL_HOURS), fn (): array => $this->fetch());
    }

    /**
     * Force a fresh read from the provider, replacing the cached catalogue.
     *
     * @return array<string, array{id: string, owned_by: string|null}>
     *
     * @throws AiUnavailableException
     */
    public function refresh(): array
    {
        Cache::forget($this->cacheKey());

        return $this->catalog();
    }

    /**
     * Credential-scoped cache key: `CACHE_KEY` plus an md5 fingerprint of the
     * base URL and api key. Only hashes are part of the key, never the key.
     */
    private function cacheKey(): string
    {
        return self::CACHE_KEY.':'.md5((string) $this->baseUrl.'|'.md5((string) $this->apiKey));
    }

    /**
     * @return array<string, array{id: string, owned_by: string|null}>
     *
     * @throws AiUnavailableException
     */
    private function fetch(): array
    {
        $this->assertConfigured();

        try {
            $response = Http::acceptJson()
                ->withToken((string) $this->apiKey)
                ->timeout($this->timeout)
                ->connectTimeout(min($this->timeout, 10))
                ->get(ProviderEndpoint::normalizeBaseUrl($this->baseUrl).'/v1/models');
        } catch (ConnectionException $e) {
            throw new AiUnavailableException(
                AiUnavailableException::CODE_EXTERNAL_UNAVAILABLE,
                ProviderEndpoint::describeConnectionFailure($e),
                null,
                $e,
            );
        }

        if (! $response->successful()) {
            throw new AiUnavailableException(
                $this->statusCode($response->status()),
                'Katalog model provider AI membalas status '.$response->status().'.',
            );
        }

        $payload = json_decode($response->body(), true);

        if (! is_array($payload)) {
            throw new AiUnavailableException(
                AiUnavailableException::CODE_CONTRACT_VIOLATION,
                'Body katalog model provider bukan objek JSON.',
            );
        }

        return $this->normalize($payload);
    }

    /**
     * Accept the OpenAI shape `data[].id` first, then tolerate a bare list or
     * a `models[]` key; entries without a string `id` are skipped.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, array{id: string, owned_by: string|null}>
     */
    private function normalize(array $payload): array
    {
        $items = match (true) {
            is_array($payload['data'] ?? null) => $payload['data'],
            is_array($payload['models'] ?? null) => $payload['models'],
            array_is_list($payload) => $payload,
            default => [],
        };

        $models = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || $item['id'] === '') {
                continue;
            }

            $ownedBy = $item['owned_by'] ?? null;

            $models[$item['id']] = [
                'id' => $item['id'],
                'owned_by' => is_string($ownedBy) ? $ownedBy : null,
            ];
        }

        return $models;
    }

    private function statusCode(int $status): string
    {
        return match ($status) {
            401 => AiUnavailableException::CODE_EXTERNAL_AUTH,
            403 => AiUnavailableException::CODE_EXTERNAL_FORBIDDEN,
            429 => AiUnavailableException::CODE_EXTERNAL_RATE_LIMIT,
            503 => AiUnavailableException::CODE_EXTERNAL_UNAVAILABLE,
            default => AiUnavailableException::CODE_EXTERNAL_ERROR,
        };
    }

    /**
     * @throws AiUnavailableException
     */
    private function assertConfigured(): void
    {
        foreach (['base_url' => $this->baseUrl, 'api_key' => $this->apiKey] as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new AiUnavailableException(
                    AiUnavailableException::CODE_PROVIDER_NOT_CONFIGURED,
                    'Konfigurasi provider AI tidak lengkap: ai.openai_compatible.'.$key.'.',
                );
            }
        }
    }
}
