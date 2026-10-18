<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Raised when a batch regrade cannot be queued because the AI gate or the
 * modul's token budget says no.
 *
 * The message is the machine-readable code of the rejecting guard
 * (`AI_DISABLED`, `EXTERNAL_PAYLOAD_DISABLED`, `PROVIDER_POLICY_UNVERIFIED`
 * or `BUDGET_EXCEEDED`) and the status is 409, so RenderApiException answers
 * `409 {"status":"error","code":"AI_DISABLED"}` — nothing was changed.
 */
class AiRegradeRejectedException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(string $code, ?Throwable $previous = null)
    {
        parent::__construct($code, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return 409;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
