<?php

namespace App\Console\Commands;

use App\Services\Ai\RawResponsePruner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * T29: drops `ai_evaluations.raw_response` older than 90 days.
 *
 *   php artisan ai:prune-raw-responses [--before=YYYY-MM-DD]
 *
 * Only the raw provider payload is cleared (set to NULL) for evaluations
 * whose `finished_at` is more than {@see RawResponsePruner::RETENTION_DAYS}
 * days in the past. Verdicts, scores, hashes and statuses are never touched.
 * The command is idempotent: a second run has nothing left to clear.
 *
 * `--before` overrides "now" and is meant for backfills/manual runs; the
 * scheduled daily run uses the current time.
 */
class AiPruneRawResponses extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ai:prune-raw-responses
                            {--before= : Treat this day (Y-m-d) as "now", defaults to the current time}';

    /**
     * @var string
     */
    protected $description = 'Set ai_evaluations.raw_response to NULL for evaluations finished more than 90 days ago';

    public function handle(RawResponsePruner $pruner): int
    {
        $option = $this->option('before');

        $now = null;

        if ($option !== null && $option !== '') {
            try {
                $now = Carbon::createFromFormat('!Y-m-d', (string) $option);
            } catch (Throwable) {
                $now = null;
            }

            if ($now === null || $now->format('Y-m-d') !== (string) $option) {
                $this->error('Opsi --before harus berformat Y-m-d dan menunjuk tanggal yang valid.');

                return self::FAILURE;
            }
        }

        try {
            $count = $pruner->prune($now);
        } catch (Throwable $exception) {
            $this->error('Gagal memangkas raw_response: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'cutoff=%s pruned=%d',
            $pruner->cutoff($now)->toDateTimeString(),
            $count,
        ));

        return self::SUCCESS;
    }
}
