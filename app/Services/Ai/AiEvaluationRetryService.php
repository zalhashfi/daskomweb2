<?php

namespace App\Services\Ai;

use App\Exceptions\AiDisabledException;
use App\Exceptions\AiEvaluationRetryRejectedException;
use App\Exceptions\ExternalPayloadDisabledException;
use App\Exceptions\ProviderPolicyUnverifiedException;
use App\Jobs\EvaluateAnswer;
use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\JawabanSubmission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-queues the `failed` evaluations of one submission for another run.
 *
 * The retry never creates a duplicate: it asks
 * App\Services\Ai\EvaluationChokepoint for the canonical rows of the
 * submission's answers (idempotent — already present rows are a no-op), then
 * flips exactly the `failed` ones back to `queued` and dispatches one
 * EvaluateAnswer job per row.
 *
 * - `done` rows are never touched; they are only counted as `skipped_done`.
 * - other statuses (`queued`, `pending`, `processing`, `manual_required`,
 *   `key_pending`) are already in flight or terminal by design and stay as-is.
 * - The failure detail (`error_code`, `error_message`, `finished_at`) and the
 *   release counter are cleared: the row no longer describes a finished run.
 * - The gate is checked before anything is touched; the row reset and the
 *   dispatch are registered as an after-commit callback, so a rollback pushes
 *   nothing.
 */
class AiEvaluationRetryService
{
    /**
     * Reset on a retried row: the previous run is history, not state.
     *
     * @var array<string, mixed>
     */
    private const RESET_COLUMNS = [
        'error_code' => null,
        'error_message' => null,
        'finished_at' => null,
        'release_count' => 0,
    ];

    public function __construct(private readonly EvaluationChokepoint $evaluations) {}

    /**
     * @return array{retried: int, skipped_done: int}
     *
     * @throws AiEvaluationRetryRejectedException when the AI gate rejects the run
     */
    public function retry(JawabanSubmission $submission): array
    {
        [$retried, $skippedDone] = DB::transaction(function () use ($submission): array {
            $this->assertGateOpen();

            $answerIds = $this->answerIds($submission);

            // Idempotent chokepoint: existing rows stay untouched, only a
            // missing canonical row (answer written before an active set) is
            // created here — still through the single creator.
            $this->evaluations->createQueuedEvaluations($submission, $answerIds);

            $rows = $this->evaluationRows($submission, $answerIds);

            $failedIds = $rows
                ->where('status', AiEvaluation::STATUS_FAILED)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            $retried = 0;

            if ($failedIds !== []) {
                AiEvaluation::query()
                    ->whereKey($failedIds)
                    ->where('status', AiEvaluation::STATUS_FAILED)
                    ->update(['status' => AiEvaluation::STATUS_QUEUED, ...self::RESET_COLUMNS]);

                $retriedRows = AiEvaluation::query()
                    ->whereKey($failedIds)
                    ->where('status', AiEvaluation::STATUS_QUEUED)
                    ->orderBy('id')
                    ->get();

                $retried = $retriedRows->count();

                DB::afterCommit(function () use ($retriedRows): void {
                    foreach ($retriedRows as $evaluation) {
                        EvaluateAnswer::dispatch($evaluation);
                    }
                });
            }

            return [$retried, $rows->where('status', AiEvaluation::STATUS_DONE)->count()];
        });

        $result = [
            'retried' => $retried,
            'skipped_done' => $skippedDone,
        ];

        Log::info('ai.evaluations_retried', [
            'submission_id' => $submission->getKey(),
            ...$result,
        ]);

        return $result;
    }

    /**
     * Gate first: a retry that cannot run must not touch a single row.
     *
     * @throws AiEvaluationRetryRejectedException
     */
    private function assertGateOpen(): void
    {
        $config = AiConfig::active();

        try {
            app(AiGate::class)->assertEnabled($config);
            $config->assertPayloadPolicy();
        } catch (Throwable $exception) {
            Log::info('ai.evaluations_retry_rejected', [
                'reason' => $exception::class,
            ]);

            throw new AiEvaluationRetryRejectedException(
                match (true) {
                    $exception instanceof AiDisabledException => AiDisabledException::ERROR_CODE,
                    $exception instanceof ProviderPolicyUnverifiedException => ProviderPolicyUnverifiedException::ERROR_CODE,
                    $exception instanceof ExternalPayloadDisabledException => ExternalPayloadDisabledException::ERROR_CODE,
                    default => 'AI_UNAVAILABLE',
                },
                $exception,
            );
        }
    }

    /**
     * The answer ids of this submission, in id order.
     *
     * @return list<int>
     */
    private function answerIds(JawabanSubmission $submission): array
    {
        $modelClass = JawabanSubmission::JAWABAN_MODELS[$submission->tipe] ?? null;

        if ($modelClass === null) {
            return [];
        }

        return $modelClass::query()
            ->where('submission_id', $submission->getKey())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $answerIds
     * @return Collection<int, AiEvaluation>
     */
    private function evaluationRows(JawabanSubmission $submission, array $answerIds): Collection
    {
        if ($answerIds === []) {
            return AiEvaluation::query()->whereKey([])->get();
        }

        return AiEvaluation::query()
            ->where('jawaban_type', $submission->tipe)
            ->whereIn('jawaban_id', $answerIds)
            ->orderBy('id')
            ->get();
    }
}
