<?php

namespace App\Services\Ai\Contracts;

use App\Exceptions\AiUnavailableException;

/**
 * Minimal outbound contract to an AI provider (spec §7.8).
 *
 * `chat()` returns the assistant text, `embed()` one vector per input text.
 * The `openai_compatible` driver (OpenAiCompatibleClient) is the only
 * implementation in v1.
 */
interface AiClient
{
    /**
     * Send a chat completion and return the assistant content.
     *
     * `model` / `usage` are optional extras echoed by the provider.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string, model?: string, usage?: array<string, mixed>}
     *
     * @throws AiUnavailableException
     */
    public function chat(array $messages): array;

    /**
     * Embed every text in the list, in order (batched to ≤64 per request).
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     *
     * @throws AiUnavailableException
     */
    public function embed(array $texts): array;
}
