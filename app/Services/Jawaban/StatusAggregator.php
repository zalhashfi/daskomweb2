<?php

namespace App\Services\Jawaban;

use App\Models\AiEvaluation;
use App\Models\JawabanSubmission;
use Illuminate\Database\Eloquent\Collection;

/**
 * Rolls the AI evaluations of one submission up into a status summary.
 *
 * A submission owns every evaluation whose `jawaban_type` equals the
 * submission `tipe` and whose `jawaban_id` points at an answer row belonging
 * to the submission (`jawaban_tps.submission_id` / `jawaban_mandiris.submission_id`).
 *
 * Note the `tidak_menjawab` distinction: it is a VERDICT of a `done`
 * evaluation, never a status of its own. `summary()` therefore splits `done`
 * rows by verdict instead of counting the status alone.
 */
class StatusAggregator
{
    public const VERDICT_TIDAK_MENJAWAB = 'tidak_menjawab';

    /**
     * Stamp `finalized_at` once every evaluation is terminal.
     *
     * A submission without any evaluation is NOT considered final. Once
     * stamped, the timestamp is kept stable on repeated calls.
     */
    public function finalize(JawabanSubmission $submission): bool
    {
        $evaluations = $this->evaluationsOf($submission);

        if ($evaluations->isEmpty()) {
            return false;
        }

        $allTerminal = $evaluations->every(
            fn (AiEvaluation $evaluation): bool => $evaluation->isTerminal()
        );

        if (! $allTerminal) {
            return false;
        }

        if ($submission->finalized_at === null) {
            $submission->forceFill(['finalized_at' => now()])->save();
        }

        return true;
    }

    /**
     * @return array{done: int, failed: int, tidak_menjawab: int, pending: int}
     */
    public function summary(JawabanSubmission $submission): array
    {
        $summary = [
            'done' => 0,
            'failed' => 0,
            'tidak_menjawab' => 0,
            'pending' => 0,
        ];

        foreach ($this->evaluationsOf($submission) as $evaluation) {
            match (true) {
                $evaluation->status === AiEvaluation::STATUS_DONE
                    && $evaluation->verdict === self::VERDICT_TIDAK_MENJAWAB => $summary['tidak_menjawab']++,
                $evaluation->status === AiEvaluation::STATUS_DONE => $summary['done']++,
                in_array($evaluation->status, [
                    AiEvaluation::STATUS_FAILED,
                    AiEvaluation::STATUS_MANUAL_REQUIRED,
                    AiEvaluation::STATUS_KEY_PENDING,
                ], true) => $summary['failed']++,
                default => $summary['pending']++,
            };
        }

        return $summary;
    }

    /**
     * Every evaluation that belongs to the submission, in a stable order.
     *
     * @return Collection<int, AiEvaluation>
     */
    protected function evaluationsOf(JawabanSubmission $submission): Collection
    {
        $modelClass = JawabanSubmission::JAWABAN_MODELS[$submission->tipe] ?? null;

        if ($modelClass === null || $submission->getKey() === null) {
            return new Collection;
        }

        $answerIds = $modelClass::query()
            ->where('submission_id', $submission->getKey())
            ->orderBy('id')
            ->pluck('id')
            ->push($submission->jawaban_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($answerIds === []) {
            return new Collection;
        }

        return AiEvaluation::query()
            ->where('jawaban_type', $submission->tipe)
            ->whereIn('jawaban_id', $answerIds)
            ->orderBy('id')
            ->get();
    }
}
