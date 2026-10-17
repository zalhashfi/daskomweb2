<?php

namespace App\Exceptions;

use App\Models\JawabanSubmission;
use Carbon\Carbon;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Raised by App\Services\Jawaban\SubmissionWriter when the latest submission
 * of a (praktikan, modul, tipe) slot already has `locked_at` set.
 *
 * The exception is itself an HTTP 409 whose message is the machine-readable
 * `SUBMISSION_LOCKED` code, so App\Exceptions\RenderApiException renders it as
 * `409 {"status":"error","code":"SUBMISSION_LOCKED","submission_id":..,"locked_at":..}`
 * for any `api-v1` route that lets it bubble up — controllers need no
 * try/catch. `errorContext()` carries the submission detail.
 */
class JawabanSubmissionLockedException extends RuntimeException implements HttpExceptionInterface
{
    public const CODE = 'SUBMISSION_LOCKED';

    public function __construct(
        public readonly int $submissionId,
        public readonly Carbon $lockedAt,
        string $message = self::CODE,
    ) {
        parent::__construct($message);
    }

    public static function fromSubmission(JawabanSubmission $submission): self
    {
        return new self((int) $submission->id, $submission->locked_at);
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
            'submission_id' => $this->submissionId,
            'locked_at' => $this->lockedAt->toIso8601String(),
        ];
    }
}
