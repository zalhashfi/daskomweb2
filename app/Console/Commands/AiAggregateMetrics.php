<?php

namespace App\Console\Commands;

use App\Services\Ai\MetricsAggregator;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Writes the daily ai_eval_metrics rollup for one day.
 *
 *   php artisan ai:aggregate-metrics [--date=YYYY-MM-DD]
 *
 * `--date` defaults to yesterday (the day that is already complete when the
 * scheduler runs right after midnight). The day is recomputed from
 * ai_evaluations on every run and upserted on (stat_date, modul_id), so
 * running it twice for the same day updates the same rows instead of
 * duplicating them: one global row (`modul_id = NULL`) plus one row per modul
 * that has evaluations that day.
 *
 * Exits non-zero when the date is invalid or when one of the upserts fails
 * (e.g. a duplicate global row for the day).
 */
class AiAggregateMetrics extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ai:aggregate-metrics
                            {--date= : Day to aggregate (Y-m-d), defaults to yesterday}';

    /**
     * @var string
     */
    protected $description = 'Aggregate ai_evaluations into ai_eval_metrics for one day';

    public function handle(MetricsAggregator $aggregator): int
    {
        $date = $this->resolveDate();

        if ($date === null) {
            $this->error('Opsi --date harus berformat Y-m-d dan menunjuk tanggal yang valid.');

            return self::FAILURE;
        }

        try {
            $rows = $aggregator->aggregate($date);
        } catch (Throwable $exception) {
            $this->error('Gagal menyimpan metrik: '.$exception->getMessage());

            return self::FAILURE;
        }

        $global = $rows[0];

        $this->line(sprintf(
            'date=%s total=%d done=%d failed=%d rows=%d',
            $date->toDateString(),
            $global->total,
            $global->done,
            $global->failed,
            count($rows),
        ));

        return self::SUCCESS;
    }

    /**
     * `--date` parsed as the start of that day, or null when invalid.
     */
    private function resolveDate(): ?Carbon
    {
        $option = $this->option('date');

        if ($option === null || $option === '') {
            return Carbon::yesterday()->startOfDay();
        }

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', (string) $option);
        } catch (InvalidFormatException) {
            return null;
        }

        if ($parsed->format('Y-m-d') !== (string) $option) {
            return null;
        }

        return $parsed->startOfDay();
    }
}
