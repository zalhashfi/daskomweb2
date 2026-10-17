<?php

namespace App\Exceptions;

/**
 * Raised by App\Services\Ai\AiGate when `ai_configs.provider_policy_verified_at`
 * is null: nobody has recorded that the provider's policy has been reviewed,
 * so the gate stays closed.
 */
class ProviderPolicyUnverifiedException extends ExternalPayloadDisabledException
{
    public const ERROR_CODE = 'PROVIDER_POLICY_UNVERIFIED';

    public function __construct(string $message = 'Kebijakan provider AI belum diverifikasi.', ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }

    public function errorCode(): string
    {
        return 'PROVIDER_POLICY_UNVERIFIED';
    }

    public function getErrorCode(): string
    {
        return 'PROVIDER_POLICY_UNVERIFIED';
    }

    public static function make(): self
    {
        return new self;
    }
}
