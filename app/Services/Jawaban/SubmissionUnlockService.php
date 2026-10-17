<?php

namespace App\Services\Jawaban;

use App\Models\JawabanSubmission;
use App\Models\SubmissionUnlock;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Unlocks a submission on behalf of a kadiv.
 *
 * The unlock clears the lock columns of the submission, appends a
 * submission_unlocks row with the mandatory reason and writes an audit entry
 * with action 'submission.unlock' and actor_type 'kadiv'.
 */
class SubmissionUnlockService
{
    public const AUDIT_ACTION = 'submission.unlock';

    public const AUDIT_SUBJECT_TYPE = 'submission';

    public const ACTOR_TYPE = 'kadiv';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Unlock the submission and record the reason.
     *
     * Returns the unlocked submission, or null when it does not exist.
     */
    public function unlock(int $submissionId, int $asistenId, string $reason): ?JawabanSubmission
    {
        $submission = DB::transaction(function () use ($submissionId, $asistenId, $reason): ?JawabanSubmission {
            $submission = JawabanSubmission::query()
                ->whereKey($submissionId)
                ->lockForUpdate()
                ->first();

            if ($submission === null) {
                return null;
            }

            SubmissionUnlock::create([
                'submission_id' => $submission->id,
                'unlocked_by_asisten_id' => $asistenId,
                'reason' => $reason,
            ]);

            $submission->forceFill([
                'locked_at' => null,
                'locked_by_type' => null,
                'locked_by_id' => null,
            ])->save();

            return $submission;
        });

        if ($submission === null) {
            return null;
        }

        $this->auditLogger->record(
            action: self::AUDIT_ACTION,
            description: "Submission #{$submission->id} dibuka kuncinya oleh kadiv.",
            context: [
                'reason' => $reason,
                'modul_id' => $submission->modul_id,
                'praktikan_id' => $submission->praktikan_id,
                'tipe' => $submission->tipe,
                'attempt_no' => $submission->attempt_no,
            ],
            subjectType: self::AUDIT_SUBJECT_TYPE,
            subjectId: (int) $submission->id,
            actorType: self::ACTOR_TYPE,
            actorId: $asistenId,
        );

        return $submission;
    }
}
