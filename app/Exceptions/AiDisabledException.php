<?php

namespace App\Exceptions;

/**
 * Raised by App\Services\Ai\AiGate when `ai_configs.ai_enabled` is not
 * strictly `true`. No provider call may happen while AI is switched off.
 */
class AiDisabledException extends ExternalPayloadDisabledException
{
    public const ERROR_CODE = 'AI_DISABLED';

    public function __construct(string $message = 'Fitur AI sedang dinonaktifkan.', ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }

    public function errorCode(): string
    {
        return 'AI_DISABLED';
    }

    public function getErrorCode(): string
    {
        return 'AI_DISABLED';
    }

    public static function make(): self
    {
        return new self;
    }
}
