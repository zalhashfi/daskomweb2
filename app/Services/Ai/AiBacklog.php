<?php

namespace App\Services\Ai;

use App\Models\AiEvalMetric;
use App\Models\AiEvaluation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Per-modul queue snapshot for `GET /api-v1/ai-backlog`.
 *
 * Reads ai_evaluations grouped by the modul of their answer row, joined the
 * same way ModuleQueueGuard resolves a modul (union of jawaban_tps /
 * jawaban_mandiris keyed by jawaban_type). Statuses:
 *
 *  - `queued`  : waiting in the queue (queued | pending);
 *  - `running` : being worked on (running | processing);
 *  - `failed`  : terminal failure, kept until someone regrades the row;
 *  - `oldest_age_s` : seconds between the oldest non-terminal row of the
 *                     modul and now — 0 when the modul has nothing waiting.
 *
 * A modul with no evaluation row at all is not listed. The result is ordered
 * by `oldest_age_s` descending so the FIFO head comes out on top; fresh
 * failures (age 0) follow, ordered by modul id.
 */
class AiBacklog
{
    /**
     * `jawaban_type` => the answer table carrying `modul_id`.
     *
     * @var array<string, string>
     */
    public const ANSWER_TABLES = [
        'tp' => 'jawaban_tps',
        'tm' => 'jawaban_mandiris',
    ];

    /**
     * Statuses counted as `queued`.
     *
     * @var list<string>
     */
    public const QUEUED_STATUSES = ['queued', 'pending'];

    /**
     * Statuses counted as `running`.
     *
     * @var list<string>
     */
    public const RUNNING_STATUSES = ['running', 'processing'];

    /**
     * @return list<array{modul_id: int, queued: int, running: int, failed: int, oldest_age_s: int}>
     */
    public function snapshot(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $modulIds = [];

        foreach (array_keys(self::ANSWER_TABLES) as $jawabanType) {
            foreach ($this->rowsFor($jawabanType) as $row) {
                $modulId = (int) $row->modul_id;

                $modulIds[$modulId] ??= [
                    'modul_id' => $modulId,
                    'queued' => 0,
                    'running' => 0,
                    'failed' => 0,
                    'oldest_age_s' => 0,
                ];

                $modulIds[$modulId]['queued'] += (int) $row->queued;
                $modulIds[$modulId]['running'] += (int) $row->running;
                $modulIds[$modulId]['failed'] += (int) $row->failed;

                if ($row->oldest_non_terminal_at !== null) {
                    $age = max(0, $now->getTimestamp() - Carbon::parse($row->oldest_non_terminal_at)->getTimestamp());
                    $modulIds[$modulId]['oldest_age_s'] = max($modulIds[$modulId]['oldest_age_s'], $age);
                }
            }
        }

        $rows = array_values($modulIds);

        usort($rows, static fn (array $a, array $b): int => ($b['oldest_age_s'] <=> $a['oldest_age_s'])
            ?: ($a['modul_id'] <=> $b['modul_id']));

        return $rows;
    }

    /**
     * Aggregated counts per modul for one answer type.
     *
     * One query per answer type: the status buckets and the oldest
     * non-terminal timestamp are aggregated together.
     *
     * @return iterable<object>
     */
    private function rowsFor(string $jawabanType): iterable
    {
        $db = AiEvalMetric::query()->getConnection();
        $isTm = $jawabanType === 'tm';
        $answerTable = self::ANSWER_TABLES[$jawabanType];

        return $db->table('ai_evaluations')
            ->join($answerTable, $answerTable.'.id', '=', 'ai_evaluations.jawaban_id')
            ->when($isTm, fn (Builder $query) => $query->where('ai_evaluations.jawaban_type', 'tm'))
            ->when(! $isTm, fn (Builder $query) => $query->where('ai_evaluations.jawaban_type', '<>', 'tm'))
            ->groupBy($answerTable.'.modul_id')
            ->select([$answerTable.'.modul_id'])
            ->selectRaw(
                'sum(case when ai_evaluations.status in (?, ?) then 1 else 0 end) as queued',
                self::QUEUED_STATUSES,
            )
            ->selectRaw(
                'sum(case when ai_evaluations.status in (?, ?) then 1 else 0 end) as running',
                self::RUNNING_STATUSES,
            )
            ->selectRaw(
                'sum(case when ai_evaluations.status = ? then 1 else 0 end) as failed',
                [AiEvaluation::STATUS_FAILED],
            )
            ->selectRaw(
                'min(case when ai_evaluations.status in (?, ?, ?, ?) then ai_evaluations.created_at end) as oldest_non_terminal_at',
                ModuleQueueGuard::NON_TERMINAL_STATUSES,
            )
            ->get();
    }
}
