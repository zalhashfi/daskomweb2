<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Raised when the AI gate (or another module-scoped AI setting) would be
 * turned ON while at least one modul still holds non-terminal AI evaluations.
 *
 * Turning AI on mid-modul would let the queue start spending tokens on work
 * that was submitted before the decision was taken, so the change is refused
 * and the operator must wait until the queue drains.
 *
 * It is itself an HTTP 409 whose message is the machine-readable
 * `CONFIG_CHANGE_MID_MODULE` code, so App\Exceptions\RenderApiException
 * renders it as
 * `409 {"status":"error","code":"CONFIG_CHANGE_MID_MODULE","active_modules":N,"pending_evaluations":N}`
 * for any `api-v1` route that lets it bubble up.
 */
class ConfigChangeMidModuleException extends RuntimeException implements HttpExceptionInterface
{
    public const CODE = 'CONFIG_CHANGE_MID_MODULE';

    public function __construct(
        public readonly int $activeModules,
        public readonly int $pendingEvaluations,
        string $message = self::CODE,
    ) {
        parent::__construct($message);
    }

    public static function fromQueue(int $activeModules, int $pendingEvaluations): self
    {
        return new self($activeModules, $pendingEvaluations);
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

    /**
     * Extra fields merged into the `409` envelope by RenderApiException.
     *
     * @return array<string, mixed>
     */
    public function errorContext(): array
    {
        return [
            'active_modules' => $this->activeModules,
            'pending_evaluations' => $this->pendingEvaluations,
        ];
    }
}
