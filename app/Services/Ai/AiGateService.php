<?php

namespace App\Services\Ai;

use App\Exceptions\ConfigChangeMidModuleException;
use App\Models\AiConfig;
use App\Models\AiEvalMetric;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Operator-facing control of the AI gate and the per-modul token budget
 * (T31). Every change goes through AiConfig, so `ai_enabled` and
 * `budget_tokens_per_modul` are actually persisted on the active snapshot the
 * same way the seeder writes them:
 *
 *  - `ai_enabled` changes in place (only `is_active` is exempt from the
 *    revision rule, and it is not touched);
 *  - `budget_tokens_per_modul` is an immutable column, so AiConfig writes a
 *    new revision row — spend then starts from zero for the new snapshot
 *    (BudgetGuard).
 *
 * Turning the gate OFF is always allowed: it only stops future provider calls
 * and leaves queued rows `queued` until they are evaluated or retried.
 * Turning it ON is refused while any modul still holds a non-terminal
 * evaluation (`ModuleQueueGuard::NON_TERMINAL_STATUSES`), so the queue can
 * never start spending mid-modul.
 *
 * Each change is audited with the old and the new value plus the reason:
 * `ai.gate.toggled` and `ai.budget.updated`.
 */
class AiGateService
{
    public const ACTION_TOGGLED = 'ai.gate.toggled';

    public const ACTION_BUDGET_UPDATED = 'ai.budget.updated';

    /**
     * How many days of ai_eval_metrics back the GET snapshot reads.
     */
    public const METRICS_WINDOW_DAYS = 30;

    public function __construct(
        private readonly ModuleQueueGuard $queue,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Current configuration plus the 30-day quality snapshot.
     *
     * `mae`, `korelasi` and `agreement` are the evaluation-weighted means over
     * the global metric rows of the window; `p95_wait_s` is the p95 of
     * `p95_latency_ms` over the same window, expressed in seconds. All four
     * are null when the window holds no usable sample.
     *
     * @return array{
     *     mae: float|null,
     *     korelasi: float|null,
     *     agreement: float|null,
     *     p95_wait_s: float|null,
     *     ai_enabled: bool,
     *     allow_external_payload: bool,
     *     provider_policy_verified_at: string|null,
     *     budget_tokens_per_modul: int
     * }
     */
    public function snapshot(): array
    {
        $config = AiConfig::active();

        $metrics = $this->recentMetrics();

        return [
            'mae' => $this->weightedMean($metrics, 'mae', 'done'),
            'korelasi' => $this->weightedMean($metrics, 'korelasi', 'done'),
            'agreement' => $this->weightedMean($metrics, 'agreement', 'done'),
            'p95_wait_s' => $this->p95WaitSeconds($metrics),
            'ai_enabled' => $config->ai_enabled === true,
            'allow_external_payload' => $config->allow_external_payload === true,
            'provider_policy_verified_at' => $config->provider_policy_verified_at?->toIso8601String(),
            'budget_tokens_per_modul' => (int) $config->budget_tokens_per_modul,
        ];
    }

    /**
     * Turn the gate on or off.
     *
     * @return array{ai_enabled: bool, previous: bool, config_id: int}
     *
     * @throws ConfigChangeMidModuleException when turning ON with a busy queue
     */
    public function toggle(bool $enabled, string $reason, ?int $actorId): array
    {
        $config = AiConfig::active();
        $previous = $config->ai_enabled === true;

        if ($enabled && ! $previous) {
            $pending = $this->pendingEvaluations();

            if ($pending !== []) {
                throw ConfigChangeMidModuleException::fromQueue(
                    count($pending),
                    array_sum($pending),
                );
            }
        }

        if ($enabled !== $previous) {
            $config->ai_enabled = $enabled;
            $config->save();
        }

        $configId = (int) $config->getKey();

        $this->audit->record(
            self::ACTION_TOGGLED,
            sprintf('AI gate turned %s.', $enabled ? 'on' : 'off'),
            ['reason' => $reason],
            AiConfig::class,
            $configId,
            'asisten',
            $actorId,
        );

        $this->auditChange($configId, self::ACTION_TOGGLED, [
            'old_value' => ['ai_enabled' => $previous],
            'new_value' => ['ai_enabled' => $enabled],
            'changed' => $enabled !== $previous,
        ]);

        return [
            'ai_enabled' => $enabled,
            'previous' => $previous,
            'config_id' => $configId,
        ];
    }

    /**
     * Replace the per-modul token budget (0 = unlimited).
     *
     * @return array{budget_tokens_per_modul: int, previous: int, config_id: int}
     */
    public function updateBudget(int $budget, string $reason, ?int $actorId): array
    {
        $config = AiConfig::active();
        $previous = (int) $config->budget_tokens_per_modul;

        if ($budget !== $previous) {
            $config->budget_tokens_per_modul = $budget;
            $config->save();
        }

        $configId = (int) $config->getKey();

        $this->audit->record(
            self::ACTION_BUDGET_UPDATED,
            sprintf('AI token budget per modul changed from %d to %d.', $previous, $budget),
            ['reason' => $reason],
            AiConfig::class,
            $configId,
            'asisten',
            $actorId,
        );

        $this->auditChange($configId, self::ACTION_BUDGET_UPDATED, [
            'old_value' => ['budget_tokens_per_modul' => $previous],
            'new_value' => ['budget_tokens_per_modul' => $budget],
            'changed' => $budget !== $previous,
        ]);

        return [
            'budget_tokens_per_modul' => $budget,
            'previous' => $previous,
            'config_id' => $configId,
        ];
    }

    /**
     * Merge the structured change (old value, new value) into the audit row
     * just written.
     *
     * AuditLogger redacts every metadata key containing `token`, which would
     * blank `budget_tokens_per_modul`, so the operator supplied `reason` goes
     * through the sanitising logger and the change is written onto the row
     * afterwards.
     *
     * @param  array<string, mixed>  $change
     */
    private function auditChange(int $configId, string $action, array $change): void
    {
        $log = AuditLog::query()
            ->where('action', $action)
            ->where('subject_type', AiConfig::class)
            ->where('subject_id', $configId)
            ->latest('id')
            ->first();

        if ($log === null) {
            return;
        }

        $log->metadata = array_merge((array) $log->metadata, $change);
        $log->save();
    }

    /**
     * `modul_id => non-terminal evaluation count`, only for moduls that still
     * hold work.
     *
     * @return array<int, int>
     */
    public function pendingEvaluations(): array
    {
        $counts = [];

        foreach (ModuleQueueGuard::ANSWER_TABLES as $jawabanType => $answerTable) {
            $isMandiri = $jawabanType === 'tm';

            $rows = DB::table('ai_evaluations')
                ->join($answerTable, $answerTable.'.id', '=', 'ai_evaluations.jawaban_id')
                ->whereIn('ai_evaluations.status', ModuleQueueGuard::NON_TERMINAL_STATUSES)
                ->where('ai_evaluations.jawaban_type', $isMandiri ? '=' : '<>', 'tm')
                ->groupBy($answerTable.'.modul_id')
                ->selectRaw($answerTable.'.modul_id as modul_id, COUNT(*) as total')
                ->get();

            foreach ($rows as $row) {
                if ($row->modul_id === null) {
                    continue;
                }

                $modulId = (int) $row->modul_id;
                $counts[$modulId] = ($counts[$modulId] ?? 0) + (int) $row->total;
            }
        }

        return $counts;
    }

    /**
     * Global metric rows of the last METRICS_WINDOW_DAYS days, newest first.
     *
     * @return list<AiEvalMetric>
     */
    private function recentMetrics(): array
    {
        return AiEvalMetric::query()
            ->whereNull('modul_id')
            ->whereDate('stat_date', '>=', now()->subDays(self::METRICS_WINDOW_DAYS - 1)->toDateString())
            ->whereDate('stat_date', '<=', now()->toDateString())
            ->orderByDesc('stat_date')
            ->get()
            ->all();
    }

    /**
     * `done`-weighted mean of one measure; null when no row carries it.
     *
     * @param  list<AiEvalMetric>  $metrics
     */
    private function weightedMean(array $metrics, string $column, string $weightColumn): ?float
    {
        $sum = 0.0;
        $weight = 0;

        foreach ($metrics as $metric) {
            $value = $metric->{$column};

            if ($value === null) {
                continue;
            }

            $rows = (int) $metric->{$weightColumn};

            if ($rows <= 0) {
                continue;
            }

            $sum += (float) $value * $rows;
            $weight += $rows;
        }

        return $weight === 0 ? null : round($sum / $weight, 4);
    }

    /**
     * Nearest-rank p95 of the daily p95 latencies, in seconds.
     *
     * @param  list<AiEvalMetric>  $metrics
     */
    private function p95WaitSeconds(array $metrics): ?float
    {
        $latencies = array_values(array_filter(
            array_map(static fn (AiEvalMetric $metric): ?int => $metric->p95_latency_ms === null
                ? null
                : (int) $metric->p95_latency_ms, $metrics),
            static fn (?int $latency): bool => $latency !== null,
        ));

        if ($latencies === []) {
            return null;
        }

        sort($latencies);

        $rank = (int) ceil(95 / 100 * count($latencies));
        $p95 = $latencies[max(0, min(count($latencies), $rank) - 1)];

        return round($p95 / 1000, 3);
    }
}
