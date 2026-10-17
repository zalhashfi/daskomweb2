<?php

namespace App\Services\Ai\Contracts;

use App\Exceptions\AiUnavailableException;

/**
 * Minimal outbound contract to an AI provider (spec §7.8).
 *
 * The body is deliberately left unimplemented here: T20c owns the
 * `openai_compatible` driver and may adjust the shapes below while keeping
 * `chat()` returning the assistant text and `embed()` returning one vector
 * per input text.
 */
interface AiClient
{
    /**
     * Send a chat completion and return the assistant content.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string}
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
