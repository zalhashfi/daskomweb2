<?php

namespace App\Services\Ai;

use App\Enums\AiRegradePriority;
use App\Exceptions\AiDisabledException;
use App\Exceptions\AiRegradeRejectedException;
use App\Exceptions\BudgetExceededException;
use App\Exceptions\ExternalPayloadDisabledException;
use App\Exceptions\ProviderPolicyUnverifiedException;
use App\Jobs\EvaluateAnswer;
use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\JawabanSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-queues every latest answer of one modul for another AI pass (T28b).
 *
 * The regrade never invents answers or rows of its own:
 *
 *  1. The gate runs first (`AiGate::assertEnabled()` + payload policy on the
 *     active config). A rejection aborts the request with
 *     `AI_GATE_DISABLED` (or the gate's own code) and touches nothing.
 *  2. `BudgetGuard::assertAvailable()` runs per modul; an exhausted budget
 *     aborts with `BUDGET_EXCEEDED` before anything is written.
 *  3. Only the LATEST submission of each praktikan for the modul (both `tp`
 *     and `tm`) is considered — earlier attempts are history.
 *  4. The canonical rows are produced by `EvaluationChokepoint`, never by this
 *     class (idempotent: an existing row is reused, not duplicated).
 *  5. `done` rows are kept exactly as they are (not overwritten, not counted).
 *     Every other non-attachment row (`failed`, `key_pending`, `queued`, ...)
 *     is reset to `queued` and one `EvaluateAnswer` job is dispatched with the
 *     requested priority. Attachment answers are stored `manual_required` by
 *     the chokepoint and never reach the job.
 *
 * The reset happens inside a transaction and the dispatch is registered with
 * `DB::afterCommit()`, so a rollback pushes nothing.
 */
class AiRegradeService
{
    /**
     * Columns describing a previous run; cleared when the row is re-queued.
     *
     * @var array<string, mixed>
     */
    private const RESET_COLUMNS = [
        'error_code' => null,
        'error_message' => null,
        'finished_at' => null,
        'release_count' => 0,
    ];

    public function __construct(
        private readonly EvaluationChokepoint $evaluations,
        private readonly AiGate $gate,
        private readonly BudgetGuard $budget,
    ) {}

    /**
     * @return array{queued: int}
     *
     * @throws AiRegradeRejectedException when the gate or the budget rejects the run
     */
    public function regrade(int $modulId, AiRegradePriority $priority): array
    {
        $config = AiConfig::active();

        $this->assertGateOpen($config);
        $this->assertBudgetAvailable($modulId, $config);

        $queued = DB::transaction(function () use ($modulId, $priority): int {
            $candidates = [];

            foreach ($this->latestSubmissions($modulId) as $submission) {
                $answerIds = $this->answerIds($submission);

                if ($answerIds === []) {
                    continue;
                }

                $this->evaluations->createQueuedEvaluations($submission, $answerIds);

                foreach ($this->regradableRows($submission, $answerIds) as $row) {
                    $candidates[] = $row;
                }
            }

            if ($candidates === []) {
                return 0;
            }

            $ids = array_map(fn (AiEvaluation $row): int => (int) $row->getKey(), $candidates);

            AiEvaluation::query()
                ->whereKey($ids)
                ->update(['status' => AiEvaluation::STATUS_QUEUED, ...self::RESET_COLUMNS]);

            $rows = AiEvaluation::query()
                ->whereKey($ids)
                ->orderBy('id')
                ->get();

            DB::afterCommit(function () use ($rows, $priority): void {
                foreach ($rows as $evaluation) {
                    EvaluateAnswer::dispatch($evaluation, $priority->value);
                }
            });

            return $rows->count();
        });

        $result = ['queued' => $queued];

        Log::info('ai.regrade_queued', [
            'modul_id' => $modulId,
            'priority' => $priority->value,
            ...$result,
        ]);

        return $result;
    }

    /**
     * Gate first: a regrade that cannot run must not touch a single row.
     *
     * @throws AiRegradeRejectedException
     */
    private function assertGateOpen(AiConfig $config): void
    {
        try {
            $this->gate->assertEnabled($config);
            $config->assertPayloadPolicy();
        } catch (Throwable $exception) {
            Log::info('ai.regrade_rejected', [
                'reason' => $exception::class,
            ]);

            throw new AiRegradeRejectedException(
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
     * @throws AiRegradeRejectedException
     */
    private function assertBudgetAvailable(int $modulId, AiConfig $config): void
    {
        try {
            $this->budget->assertAvailable($modulId, $config);
        } catch (BudgetExceededException $exception) {
            Log::info('ai.regrade_rejected', [
                'reason' => $exception::class,
                'modul_id' => $modulId,
            ]);

            throw new AiRegradeRejectedException($exception->getErrorCode(), $exception);
        }
    }

    /**
     * Latest attempt (highest `attempt_no`) of every praktikan for the modul,
     * typed `tp` first and `tm` second.
     *
     * @return list<JawabanSubmission>
     */
    private function latestSubmissions(int $modulId): array
    {
        $submissions = [];

        foreach (array_keys(JawabanSubmission::JAWABAN_MODELS) as $tipe) {
            $latestIds = DB::table('jawaban_submissions')
                ->select('id')
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY praktikan_id ORDER BY attempt_no DESC, id DESC) AS attempt_rank')
                ->where('modul_id', $modulId)
                ->where('tipe', $tipe)
                ->orderBy('id');

            $ids = JawabanSubmission::query()
                ->fromSub($latestIds, 'latest')
                ->where('attempt_rank', 1)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            foreach (JawabanSubmission::query()->whereKey($ids)->orderBy('id')->get() as $submission) {
                $submissions[] = $submission;
            }
        }

        return $submissions;
    }

    /**
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
     * Rows the regrade may re-queue: non-terminal and not already heading for
     * a run. `done` and `manual_required` answers are kept as they are.
     *
     * @param  list<int>  $answerIds
     * @return list<AiEvaluation>
     */
    private function regradableRows(JawabanSubmission $submission, array $answerIds): array
    {
        return AiEvaluation::query()
            ->where('jawaban_type', $submission->tipe)
            ->whereIn('jawaban_id', $answerIds)
            ->whereNotIn('status', [
                AiEvaluation::STATUS_DONE,
                AiEvaluation::STATUS_MANUAL_REQUIRED,
                AiEvaluation::STATUS_QUEUED,
                AiEvaluation::STATUS_PENDING,
                AiEvaluation::STATUS_PROCESSING,
            ])
            ->orderBy('id')
            ->get()
            ->all();
    }
}
