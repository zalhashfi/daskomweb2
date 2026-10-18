<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Raised when a manual evaluation retry cannot be queued because the active
 * AI gate or payload policy says no (`AiGate::assertEnabled()` /
 * `AiConfig::assertPayloadPolicy()`).
 *
 * Unlike the submit path — which silently queues nothing — an explicit retry
 * request must tell the operator why nothing was dispatched. The exception is
 * an HTTP 422 whose message is the machine-readable code of the rejecting
 * exception, rendered by RenderApiException as
 * `422 {"status":"error","code":"EXTERNAL_PAYLOAD_DISABLED"}`.
 */
class AiEvaluationRetryRejectedException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(string $code, ?Throwable $previous = null)
    {
        parent::__construct($code, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return 422;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
