<?php

namespace App\Services\Ai;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * T29: 90 day retention for `ai_evaluations.raw_response`.
 *
 * The raw provider payload is only kept for debugging/audit for
 * {@see self::RETENTION_DAYS} days after the evaluation finished (measured
 * from `finished_at`). Once the window has passed the column is set to NULL;
 * nothing else is touched, so `verdict`, `ai_score`, `answer_hash`, `status`
 * and the remaining columns stay intact and the metrics rollup keeps working.
 *
 * Rows without `finished_at` (still queued/running, or never completed) are
 * never pruned: their age cannot be established. The update only matches
 * rows whose `raw_response` is still filled, which makes it idempotent — a
 * second run updates nothing.
 */
class RawResponsePruner
{
    /**
     * Days an `ai_evaluations.raw_response` is retained after `finished_at`.
     */
    public const RETENTION_DAYS = 90;

    protected string $table = 'ai_evaluations';

    /**
     * The cutoff: rows finished strictly before this instant are expired.
     */
    public function cutoff(?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now())->copy()->subDays(self::RETENTION_DAYS);
    }

    /**
     * NULL the `raw_response` of every expired evaluation; returns the number
     * of rows that still carried a payload and were cleared by this run.
     */
    public function prune(?Carbon $now = null): int
    {
        $cutoff = $this->cutoff($now);

        return DB::table($this->table)
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $cutoff)
            ->whereNotNull('raw_response')
            ->update([
                'raw_response' => null,
                'updated_at' => $now ?? Carbon::now(),
            ]);
    }
}
