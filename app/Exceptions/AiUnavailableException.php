<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A provider call did not produce a usable answer.
 *
 * `errorCode` is the stable internal code from spec §7.8
 * (`EXTERNAL_AUTH`, `EXTERNAL_RATE_LIMIT`, `INVALID_MODEL`, ...) that callers
 * persist / branch on; it never carries provider prose or secrets.
 */
class AiUnavailableException extends RuntimeException
{
    public const CODE_EXTERNAL_AUTH = 'EXTERNAL_AUTH';

    public const CODE_EXTERNAL_FORBIDDEN = 'EXTERNAL_FORBIDDEN';

    public const CODE_INVALID_MODEL = 'INVALID_MODEL';

    public const CODE_EMBED_NOT_SUPPORTED = 'EMBED_NOT_SUPPORTED';

    public const CODE_PROVIDER_NOT_CONFIGURED = 'PROVIDER_NOT_CONFIGURED';

    public const CODE_EXTERNAL_RATE_LIMIT = 'EXTERNAL_RATE_LIMIT';

    public const CODE_EXTERNAL_UNAVAILABLE = 'EXTERNAL_UNAVAILABLE';

    public const CODE_EXTERNAL_ERROR = 'EXTERNAL_ERROR';

    public const CODE_SSE_NOT_EXPECTED = 'SSE_NOT_EXPECTED';

    public const CODE_CONTRACT_VIOLATION = 'CONTRACT_VIOLATION';

    /**
     * Temporary code for the T20b placeholder client; T20c removes it.
     */
    public const CODE_DRIVER_NOT_IMPLEMENTED = 'DRIVER_NOT_IMPLEMENTED';

    private string $errorCode;

    /**
     * @param  string  $errorCode  one of the CODE_* constants (or a new stable code)
     * @param  int|null  $retryAfter  seconds from the provider's `retry-after`, if any
     */
    public function __construct(
        string $errorCode,
        string $message = '',
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, 0, $previous);

        $this->errorCode = $errorCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Build from a provider status/body pair mapping; retryAfter is only
     * meaningful for rate-limit / unavailable responses.
     *
     * @param  array<string, mixed>  $context  extra diagnostic keys (never the api key)
     */
    public static function make(string $errorCode, string $message = '', ?int $retryAfter = null, array $context = []): self
    {
        if ($context !== []) {
            $message = trim($message.' '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return new self($errorCode, $message, $retryAfter);
    }

    public static function notImplemented(string $method): self
    {
        return new self(
            self::CODE_DRIVER_NOT_IMPLEMENTED,
            'Driver openai_compatible belum diimplementasikan (T20c): '.$method.'().',
        );
    }

    /**
     * Provider answered 503 / all accounts unavailable.
     */
    public static function unavailable(?int $retryAfter = null): self
    {
        return new self(self::CODE_EXTERNAL_UNAVAILABLE, 'Provider AI sedang tidak tersedia.', $retryAfter);
    }

    /**
     * Provider answered 429.
     */
    public static function rateLimited(?int $retryAfter = null): self
    {
        return new self(self::CODE_EXTERNAL_RATE_LIMIT, 'Rate limit provider AI tercapai.', $retryAfter);
    }

    public static function notConfigured(): self
    {
        return new self(self::CODE_PROVIDER_NOT_CONFIGURED, 'Provider AI belum memiliki kredensial aktif.');
    }
}
