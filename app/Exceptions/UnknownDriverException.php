<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised by App\Services\Ai\AiClientManager when `ai_configs.driver` or
 * `ai_configs.embed_driver` names a driver that v1 does not implement.
 *
 * There is no fallback provider, so an unknown driver (e.g. the retired
 * `ollama`) stops the call instead of silently switching implementations.
 */
class UnknownDriverException extends RuntimeException
{
    /**
     * @param  string  $column  `driver` or `embed_driver`
     * @param  string|null  $driver  the unsupported value
     */
    public function __construct(
        public readonly string $column,
        public readonly ?string $driver,
    ) {
        parent::__construct(sprintf(
            'Driver AI tidak dikenal pada kolom %s: %s.',
            $column,
            $driver === null || $driver === '' ? '(kosong)' : $driver,
        ));
    }

    public static function make(string $column, ?string $driver): self
    {
        return new self($column, $driver);
    }
}
