<?php

namespace App\Services\Ai;

use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\JawabanSubmission;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * THE single creator of `ai_evaluations` rows (SPEC 02-01 F1 / T23a).
 *
 * Submit, retry (T22c) and regrade (T28b) all funnel through
 * `createQueuedEvaluations()`; jobs and endpoints never insert rows
 * themselves (they update the row this class created).
 *
 * Rules, in the order they are applied:
 *
 *  1. `$submission->version_set_id` null → nothing is created (no active
 *     version set at submit time), return 0.
 *  2. The gate runs BEFORE the first row exists: `AiGate::assertEnabled()`
 *     (ai_enabled / allow_external_payload / provider policy) and
 *     `AiConfig::assertPayloadPolicy()`. Any rejection → 0 rows, return 0.
 *     A disabled gate therefore leaves no half-queued work behind.
 *  3. One row per requested answer id: `queued` for a text answer,
 *     `manual_required` for an attachment answer (still created here — the
 *     job must never see it). `answer_hash` = sha256 of the answer text
 *     truncated to `AiConfig::max_answer_chars`, `driver` = config driver.
 *  4. Idempotent: the unique key (jawaban_id, answer_hash, version_set_id,
 *     ai_config_id) makes a second call a no-op; the duplicate-key error is
 *     caught row by row so one already-present row never aborts the others.
 *
 * LAMPIRAN RULE
 * -------------
 * SubmissionWriter normalises a blank answer to '-', so the text alone
 * cannot tell an attachment from an empty answer. An answer is treated as an
 * attachment when the soal it answers has `enable_file_upload` enabled on the
 * question that version set was built from:
 *
 *   version set → soal_version_items(version_set_id, soal_type='tp',
 *                  soal_id IN answerIds) → soal_tps.enable_file_upload = true
 *
 * The relation falls back to the soal's CURRENT `enable_file_upload` flag
 * when the version item carries no soal_id (soal deleted after the snapshot)
 * and, for the same reason, when no version item exists at all.
 */
class EvaluationChokepoint
{
    public function __construct(private readonly AiGate $gate) {}

    /**
     * Create the canonical evaluation row of every given answer.
     *
     * @param  array<int, int|string>  $answerIds  ids in jawaban_tps / jawaban_mandiris
     * @return int number of rows CREATED by this call
     */
    public function createQueuedEvaluations(JawabanSubmission $submission, array $answerIds): int
    {
        if ($submission->version_set_id === null) {
            return 0;
        }

        $config = AiConfig::active();

        try {
            $this->gate->assertEnabled($config);
            $config->assertPayloadPolicy();
        } catch (Throwable $exception) {
            Log::info('ai.evaluations_not_queued', [
                'submission_id' => $submission->getKey(),
                'reason' => $exception::class,
            ]);

            return 0;
        }

        $answerIds = $this->uniqueAnswerIds($answerIds);

        if ($answerIds === []) {
            return 0;
        }

        $modelClass = JawabanSubmission::JAWABAN_MODELS[$submission->tipe] ?? null;

        if ($modelClass === null) {
            return 0;
        }

        $answers = $modelClass::query()
            ->whereIn('id', $answerIds)
            ->get()
            ->keyBy(fn ($row): int => (int) $row->getKey());

        [$attachmentSoalIds, $knownSoalIds] = $this->attachmentMap($submission, $answers->values()->all());

        $created = 0;

        foreach ($answerIds as $answerId) {
            $answer = $answers->get($answerId);

            if ($answer === null) {
                continue;
            }

            if ($this->insert($submission, $answer, $config, $attachmentSoalIds, $knownSoalIds)) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Insert one row; `false` when it already existed (or lost a race for the
     * same idempotency key).
     *
     * @param  array<int, true>  $attachmentSoalIds
     * @param  array<int, true>  $knownSoalIds
     */
    private function insert(
        JawabanSubmission $submission,
        object $answer,
        AiConfig $config,
        array $attachmentSoalIds,
        array $knownSoalIds,
    ): bool {
        $soalId = (int) $answer->soal_id;
        $isAttachment = $this->isAttachment($soalId, $attachmentSoalIds, $knownSoalIds);

        try {
            AiEvaluation::query()->create([
                'jawaban_id' => (int) $answer->getKey(),
                'jawaban_type' => $submission->tipe,
                'answer_hash' => $this->answerHash((string) ($answer->jawaban ?? ''), $config),
                'version_set_id' => (int) $submission->version_set_id,
                'ai_config_id' => (int) $config->getKey(),
                'status' => $isAttachment
                    ? AiEvaluation::STATUS_MANUAL_REQUIRED
                    : AiEvaluation::STATUS_QUEUED,
                'driver' => $config->driver,
            ]);

            return true;
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * sha256 of the answer text truncated to `max_answer_chars`.
     */
    private function answerHash(string $answer, AiConfig $config): string
    {
        $limit = (int) $config->max_answer_chars;

        if ($limit <= 0) {
            $limit = (int) config('ai.max_answer_chars', 10000);
        }

        if ($limit > 0 && mb_strlen($answer) > $limit) {
            $answer = mb_substr($answer, 0, $limit);
        }

        return hash('sha256', $answer);
    }

    /**
     * soal_id => true for the answers whose soal has file upload enabled.
     *
     * `$knownSoalIds` holds every soal_id we could resolve anything for, so
     * a soal that is unknown here (not in the version set, deleted) keeps the
     * normal `queued` status instead of blocking on a missing flag.
     *
     * @param  list<object>  $answers
     * @return array{0: array<int, true>, 1: array<int, true>}
     */
    private function attachmentMap(JawabanSubmission $submission, array $answers): array
    {
        $soalIds = array_values(array_unique(array_map(
            fn (object $answer): int => (int) $answer->soal_id,
            $answers
        )));

        if ($soalIds === []) {
            return [[], []];
        }

        $items = SoalVersionItem::query()
            ->where('version_set_id', $submission->version_set_id)
            ->where('soal_type', $submission->tipe)
            ->whereIn('soal_id', $soalIds)
            ->get(['soal_id']);

        $known = array_fill_keys($soalIds, true);

        foreach ($items as $item) {
            $known[(int) $item->soal_id] = true;
        }

        if ($submission->tipe !== JawabanSubmission::TIPE_TP) {
            // Only TP questions carry an attachment flag in v1.
            return [[], $known];
        }

        $flagged = SoalTp::withTrashed()
            ->whereIn('id', $soalIds)
            ->where('enable_file_upload', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return [array_fill_keys($flagged, true), $known];
    }

    /**
     * @param  array<int, true>  $attachmentSoalIds
     * @param  array<int, true>  $knownSoalIds
     */
    private function isAttachment(int $soalId, array $attachmentSoalIds, array $knownSoalIds): bool
    {
        return isset($knownSoalIds[$soalId]) && isset($attachmentSoalIds[$soalId]);
    }

    /**
     * @param  array<int, int|string>  $answerIds
     * @return list<int>
     */
    private function uniqueAnswerIds(array $answerIds): array
    {
        $ids = [];

        foreach ($answerIds as $id) {
            if (is_numeric($id)) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Same driver codes SubmissionWriter treats as a duplicate key.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($driverCode, [1062, 2601, 2627], true)
            || in_array((int) $exception->getCode(), [1062, 2601, 2627], true)
            || ((string) $exception->getCode() === '23000' && str_contains($exception->getMessage(), '1062'));
    }
}
