<?php

namespace App\Services\Jawaban;

use App\Exceptions\DuplicateSoalException;
use App\Exceptions\JawabanSubmissionLockedException;
use App\Jobs\EvaluateAnswer;
use App\Models\AiEvaluation;
use App\Models\JawabanSubmission;
use App\Models\Praktikan;
use App\Models\SoalVersionSet;
use App\Services\Ai\EvaluationChokepoint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Single entry point for writing a practicum answer submission.
 *
 * Every successful call creates a NEW attempt for the
 * (praktikan, modul, tipe) slot: answers are never overwritten. The attempt
 * number is computed inside the transaction after a `SELECT ... FOR UPDATE`
 * on the latest submission row (or on the praktikan row when the slot has no
 * submission yet). If a concurrent request still wins the unique
 * (praktikan_id, modul_id, tipe, attempt_no) index, the write is retried
 * exactly once with the next attempt number.
 *
 * The attempt is also the T23a chokepoint: it stores the modul's ACTIVE
 * version set on the submission, then asks
 * App\Services\Ai\EvaluationChokepoint to create the canonical
 * `ai_evaluations` rows for those answers. No other code path inserts there.
 */
class SubmissionWriter
{
    public const MAX_CREATE_ATTEMPTS = 2;

    public function __construct(private readonly EvaluationChokepoint $evaluations) {}

    /**
     * Driver error numbers that mean "duplicate key for a unique index".
     *
     * @var list<int>
     */
    private const UNIQUE_DRIVER_ERROR_CODES = [1062, 2601, 2627];

    /**
     * @param  array<int, array{soal_id:int, jawaban?:string|null}>  $answers
     *
     * @throws DuplicateSoalException when a soal_id appears twice in $answers
     * @throws JawabanSubmissionLockedException when the latest attempt is locked
     */
    public function write(string $tipe, int $praktikanId, int $modulId, array $answers): JawabanSubmission
    {
        $answers = $this->normalizeAnswers($answers);

        $createAttempt = function () use ($tipe, $praktikanId, $modulId, $answers): JawabanSubmission {
            return $this->writeAttempt($tipe, $praktikanId, $modulId, $answers);
        };

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($createAttempt);
            } catch (QueryException $exception) {
                if ($attempt >= self::MAX_CREATE_ATTEMPTS || ! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Run one write inside an already open transaction.
     *
     * @param  list<array{soal_id:int, jawaban:string}>  $answers
     */
    protected function writeAttempt(string $tipe, int $praktikanId, int $modulId, array $answers): JawabanSubmission
    {
        $latest = $this->lockLatestSubmission($tipe, $praktikanId, $modulId);

        if ($latest !== null && $latest->locked_at !== null) {
            throw JawabanSubmissionLockedException::fromSubmission($latest);
        }

        $attemptNo = $latest?->attempt_no + 1 ?? 1;

        $modelClass = JawabanSubmission::JAWABAN_MODELS[$tipe] ?? null;

        if ($modelClass === null) {
            throw new InvalidArgumentException("Tipe submission tidak didukung: {$tipe}.");
        }

        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikanId,
            'modul_id' => $modulId,
            'tipe' => $tipe,
            'attempt_no' => $attemptNo,
            'version_set_id' => $this->activeVersionSetId($modulId),
        ]);

        $firstAnswerId = null;
        $answerIds = [];

        foreach ($answers as $answer) {
            $row = $modelClass::create([
                'praktikan_id' => $praktikanId,
                'modul_id' => $modulId,
                'soal_id' => $answer['soal_id'],
                'submission_id' => $submission->id,
                'jawaban' => $answer['jawaban'],
            ]);

            $firstAnswerId ??= $row->getKey();
            $answerIds[] = (int) $row->getKey();
        }

        $submission->forceFill(['jawaban_id' => $firstAnswerId])->save();

        // Chokepoint (T23a): rows are created inside this transaction so a
        // rollback leaves neither answers nor evaluations behind.
        $created = $this->evaluations->createQueuedEvaluations($submission, $answerIds);

        if ($created > 0) {
            $this->dispatchAfterCommit($submission, $answerIds);
        }

        return $submission;
    }

    /**
     * T27: one EvaluateAnswer job per `queued` row this submit created.
     *
     * Only `queued` rows are dispatched — an attachment answer is stored as
     * `manual_required` by the chokepoint and must never reach the job.
     *
     * The dispatch is registered as an after-commit callback, folded into the
     * ROOT transaction level, so a rolled back submit pushes nothing.
     *
     * @param  list<int>  $answerIds
     */
    private function dispatchAfterCommit(JawabanSubmission $submission, array $answerIds): void
    {
        $evaluations = AiEvaluation::query()
            ->where('jawaban_type', $submission->tipe)
            ->whereIn('jawaban_id', $answerIds)
            ->where('status', AiEvaluation::STATUS_QUEUED)
            ->orderBy('id')
            ->get();

        $dispatch = function () use ($evaluations): void {
            foreach ($evaluations as $evaluation) {
                EvaluateAnswer::dispatch($evaluation);
            }
        };

        DB::afterCommit($dispatch);
    }

    /**
     * The version set currently in force for the modul: activated_at not null,
     * newest first. Null when the modul has no active set yet.
     */
    protected function activeVersionSetId(int $modulId): ?int
    {
        $id = SoalVersionSet::query()
            ->forModul($modulId)
            ->active()
            ->orderByDesc('activated_at')
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Lock the latest attempt of the slot, or the praktikan row when the slot
     * has no submission yet so concurrent first submits serialise too.
     */
    protected function lockLatestSubmission(string $tipe, int $praktikanId, int $modulId): ?JawabanSubmission
    {
        $latest = JawabanSubmission::query()
            ->where('praktikan_id', $praktikanId)
            ->where('modul_id', $modulId)
            ->where('tipe', $tipe)
            ->orderByDesc('attempt_no')
            ->lockForUpdate()
            ->first();

        if ($latest === null) {
            Praktikan::query()->whereKey($praktikanId)->lockForUpdate()->first();
        }

        return $latest;
    }

    /**
     * @param  array<int, array{soal_id:int|string, jawaban?:string|null}>  $answers
     * @return list<array{soal_id:int, jawaban:string}>
     */
    protected function normalizeAnswers(array $answers): array
    {
        $normalized = Collection::make($answers)
            ->map(fn (array $answer): array => [
                'soal_id' => (int) $answer['soal_id'],
                'jawaban' => $this->normalizeJawaban($answer['jawaban'] ?? null),
            ])
            ->values();

        $duplicates = $normalized
            ->countBy('soal_id')
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->values()
            ->all();

        if ($duplicates !== []) {
            throw DuplicateSoalException::make($duplicates);
        }

        return $normalized->all();
    }

    protected function normalizeJawaban(?string $jawaban): string
    {
        $trimmed = trim((string) $jawaban);

        return $trimmed === '' ? '-' : $trimmed;
    }

    /**
     * MySQL reports a duplicate key as driver error 1062 (SQLSTATE 23000);
     * SQL Server uses 2601/2627.
     */
    protected function isUniqueViolation(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if (in_array($driverCode, self::UNIQUE_DRIVER_ERROR_CODES, true)) {
            return true;
        }

        $code = (int) $exception->getCode();

        return in_array($code, self::UNIQUE_DRIVER_ERROR_CODES, true)
            || ((string) $exception->getCode() === '23000' && str_contains($exception->getMessage(), '1062'));
    }
}
