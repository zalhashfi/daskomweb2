<?php

namespace App\Exceptions;

use App\Models\JawabanSubmission;
use Carbon\Carbon;
use RuntimeException;

/**
 * Raised by App\Services\Jawaban\SubmissionWriter when the latest submission
 * of a (praktikan, modul, tipe) slot already has `locked_at` set.
 *
 * The controller turns this into `409 {"code":"SUBMISSION_LOCKED"}`.
 */
class JawabanSubmissionLockedException extends RuntimeException
{
    public function __construct(
        public readonly int $submissionId,
        public readonly Carbon $lockedAt,
        string $message = 'Jawaban sudah dikunci.',
    ) {
        parent::__construct($message);
    }

    public static function fromSubmission(JawabanSubmission $submission): self
    {
        return new self((int) $submission->id, $submission->locked_at);
    }
}
