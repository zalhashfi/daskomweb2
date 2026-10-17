<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised by App\Models\AiConfig::assertPayloadPolicy() when the active
 * snapshot does not explicitly allow sending content to an external AI
 * provider (`allow_external_payload !== true`).
 *
 * The guard is a whitelist: NULL, 0 and the string "false" are all treated
 * as "disabled" so a mis-provisioned row fails closed instead of leaking
 * answer text (spec §7.8, E24/E32).
 */
class ExternalPayloadDisabledException extends RuntimeException
{
    public const ERROR_CODE = 'EXTERNAL_PAYLOAD_DISABLED';

    public function __construct(string $message = 'Payload eksternal dinonaktifkan untuk konfigurasi AI aktif.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }
}
