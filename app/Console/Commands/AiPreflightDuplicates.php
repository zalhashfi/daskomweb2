<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Read-only preflight that measures duplicate answer groups in
 * jawaban_tps / jawaban_mandiris.
 *
 * A duplicate group is every row sharing (praktikan_id, modul_id, soal_id)
 * with a count greater than one. The command never writes: it only runs
 * SELECT statements, prints one machine-readable summary line (`tp=<N> tm=<M>`)
 * and, when `--fail-on-duplicate` is given, exits non-zero if any duplicate
 * group exists.
 */
class AiPreflightDuplicates extends Command
{
    public const SOURCE_TABLES = [
        'tp' => 'jawaban_tps',
        'tm' => 'jawaban_mandiris',
    ];

    /**
     * @var string
     */
    protected $signature = 'ai:preflight-duplicates
                            {--fail-on-duplicate : Exit with code 1 when any duplicate group exists}';

    /**
     * @var string
     */
    protected $description = 'Measure duplicate answer groups in jawaban_tps / jawaban_mandiris (read-only)';

    public function handle(): int
    {
        $tp = $this->countDuplicateGroups(DB::connection(), self::SOURCE_TABLES['tp']);
        $tm = $this->countDuplicateGroups(DB::connection(), self::SOURCE_TABLES['tm']);

        $this->line(sprintf('tp=%d tm=%d', $tp, $tm));

        if (! (bool) $this->option('fail-on-duplicate')) {
            return self::SUCCESS;
        }

        return ($tp === 0 && $tm === 0) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Number of (praktikan_id, modul_id, soal_id) slots holding more than one row.
     */
    protected function countDuplicateGroups(ConnectionInterface $source, string $table): int
    {
        return (int) $source->table($table)
            ->select('praktikan_id', 'modul_id', 'soal_id')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('praktikan_id', 'modul_id', 'soal_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();
    }
}
