<?php

namespace App\Services\Jawaban;

use App\Models\JawabanSubmission;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Locks submissions on behalf of the system.
 *
 * Only the latest 'tp' attempt of a (praktikan, modul) slot is locked; 'tm'
 * submissions are never touched. Every lock is audited with
 * action 'submission.locked' and actor_type 'system'.
 */
class SubmissionLockService
{
    public const AUDIT_ACTION = 'submission.locked';

    public const AUDIT_SUBJECT_TYPE = 'submission';

    public const LOCKED_BY_TYPE = 'system';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Lock the latest 'tp' submission of the slot, if it is not locked yet.
     *
     * Returns the locked submission, or null when the slot has no submission.
     */
    public function lockLatestForGrade(int $praktikanId, int $modulId): ?JawabanSubmission
    {
        $submission = DB::transaction(function () use ($praktikanId, $modulId): ?JawabanSubmission {
            $latest = JawabanSubmission::query()
                ->where('praktikan_id', $praktikanId)
                ->where('modul_id', $modulId)
                ->where('tipe', JawabanSubmission::TIPE_TP)
                ->orderByDesc('attempt_no')
                ->lockForUpdate()
                ->first();

            if ($latest === null || $latest->isLocked()) {
                return null;
            }

            $latest->forceFill([
                'locked_at' => now(),
                'locked_by_type' => self::LOCKED_BY_TYPE,
                'locked_by_id' => null,
            ])->save();

            return $latest;
        });

        if ($submission === null) {
            return null;
        }

        $this->auditLogger->record(
            action: self::AUDIT_ACTION,
            description: "Submission TP #{$submission->id} dikunci otomatis setelah penilaian.",
            context: [
                'modul_id' => $modulId,
                'praktikan_id' => $praktikanId,
                'tipe' => JawabanSubmission::TIPE_TP,
                'attempt_no' => $submission->attempt_no,
                'locked_at' => $submission->locked_at?->toIso8601String(),
            ],
            subjectType: self::AUDIT_SUBJECT_TYPE,
            subjectId: (int) $submission->id,
            actorType: self::LOCKED_BY_TYPE,
        );

        return $submission;
    }
}
