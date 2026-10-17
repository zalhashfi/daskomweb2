<?php

namespace App\Services\Ai;

use App\Exceptions\UnknownDriverException;
use App\Models\AiConfig;
use App\Services\Ai\Contracts\AiClient;

/**
 * Resolves the AI client for a single concern (chat vs. embedding).
 *
 * The manager never queries the database: the caller hands in the already
 * active AiConfig row. `forChat()` reads only `driver`, `forEmbed()` only
 * `embed_driver`, so the two resolutions stay independent.
 *
 * v1 knows exactly one driver (`openai_compatible` → 9router). Every other
 * value — notably the retired `ollama` — fails loudly; there is no fallback
 * provider (spec Q22a/Q30).
 */
class AiClientManager
{
    public const DRIVER_OPENAI_COMPATIBLE = 'openai_compatible';

    /**
     * Resolve the chat client for the config's `driver` column.
     *
     * @throws UnknownDriverException
     */
    public function forChat(AiConfig $config): AiClient
    {
        return $this->make($config->driver, 'driver');
    }

    /**
     * Resolve the embedding client for the config's `embed_driver` column.
     *
     * @throws UnknownDriverException
     */
    public function forEmbed(AiConfig $config): AiClient
    {
        return $this->make($config->embed_driver, 'embed_driver');
    }

    /**
     * @throws UnknownDriverException
     */
    private function make(?string $driver, string $column): AiClient
    {
        return match ($driver) {
            self::DRIVER_OPENAI_COMPATIBLE => new OpenAiCompatibleClient,
            default => throw UnknownDriverException::make($column, $driver),
        };
    }
}
