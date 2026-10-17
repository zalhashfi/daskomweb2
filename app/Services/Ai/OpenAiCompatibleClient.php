<?php

namespace App\Services\Ai;

use App\Exceptions\AiUnavailableException;
use App\Services\Ai\Contracts\AiClient;

/**
 * Gateway to an OpenAI-compatible endpoint (9router), spec §7.8.
 *
 * Placeholder for T20b: it is constructible and selectable through
 * AiClientManager, but no HTTP call is made yet. T20c replaces the method
 * bodies with the real `/v1/chat/completions` and `/v1/embeddings` requests.
 *
 * @todo T20c — implement the HTTP driver (stream:false, Accept: application/json,
 *       Bearer auth, status/body error mapping, embedding batches of ≤64).
 */
class OpenAiCompatibleClient implements AiClient
{
    /**
     * @param  string|null  $baseUrl  Gateway root, e.g. `https://router.local`.
     * @param  string|null  $apiKey  Bearer token; never logged.
     * @param  string|null  $chatModel  Model id used by chat().
     * @param  string|null  $embedModel  Model id used by embed().
     */
    public function __construct(
        public readonly ?string $baseUrl = null,
        public readonly ?string $apiKey = null,
        public readonly ?string $chatModel = null,
        public readonly ?string $embedModel = null,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string}
     *
     * @throws AiUnavailableException
     */
    public function chat(array $messages): array
    {
        throw AiUnavailableException::notImplemented('chat');
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     *
     * @throws AiUnavailableException
     */
    public function embed(array $texts): array
    {
        throw AiUnavailableException::notImplemented('embed');
    }
}
