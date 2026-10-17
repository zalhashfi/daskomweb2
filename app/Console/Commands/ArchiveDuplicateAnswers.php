<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Moves duplicate rows out of jawaban_tps / jawaban_mandiris into
 * jawaban_duplicates_archive.
 *
 * A duplicate group is every row sharing (praktikan_id, modul_id, soal_id)
 * with a count greater than one. Inside a group:
 *
 * - distinct created_at values => the row with MAX(id) wins, the rest are
 *   copied to the archive and deleted from the source table;
 * - repeated created_at values => the group is ambiguous (no deterministic
 *   winner), nothing is moved and the command exits FAILURE.
 *
 * Every archived row is keyed by the unique (source_table, source_id) index,
 * so re-running the command is a no-op. The `--dry-run` option reports the
 * same verdict without writing anything.
 */
class ArchiveDuplicateAnswers extends Command
{
    public const ARCHIVED_REASON = 'duplicate_superseded';

    public const SOURCE_TABLES = [
        'jawaban_tps' => 'tp',
        'jawaban_mandiris' => 'tm',
    ];

    /**
     * @var string
     */
    protected $signature = 'jawaban:archive-duplicates
                            {--dry-run : Report the planned archive without writing or deleting anything}';

    /**
     * @var string
     */
    protected $description = 'Archive superseded duplicate answers from jawaban_tps / jawaban_mandiris';

    public function handle(): int
    {
        if (! Schema::hasTable('jawaban_duplicates_archive')) {
            $this->error('Tabel jawaban_duplicates_archive belum ada. Jalankan `php artisan migrate` terlebih dahulu.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Mode dry-run: tidak ada data yang diubah.');
        }

        $archived = 0;
        $ambiguousGroups = [];

        foreach (self::SOURCE_TABLES as $sourceTable => $tipe) {
            $source = DB::connection();

            foreach ($this->duplicateGroups($source, $sourceTable) as $group) {
                $result = $this->processGroup($source, $sourceTable, $tipe, $group, $dryRun);

                if ($result === null) {
                    $ambiguousGroups[] = $this->describeAmbiguousGroup($sourceTable, $group);
                    $this->warn('AMBIGU: '.end($ambiguousGroups));

                    continue;
                }

                $archived += $result;
            }
        }

        $this->info(sprintf(
            '%s %d baris duplikat dari %d tabel sumber.',
            $dryRun ? 'Akan diarsipkan' : 'Diarsipkan',
            $archived,
            count(self::SOURCE_TABLES)
        ));

        if ($ambiguousGroups !== []) {
            $this->error(sprintf(
                '%d grup ambigu (created_at identik) ditemukan; tidak ada baris dari grup tersebut yang dipindahkan.',
                count($ambiguousGroups)
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Fetch every (praktikan_id, modul_id, soal_id) slot holding more than one row.
     *
     * @return Collection<int, object{praktikan_id: int, modul_id: int, soal_id: int, aggregate: int}>
     */
    protected function duplicateGroups(ConnectionInterface $source, string $table): Collection
    {
        return $source->table($table)
            ->select('praktikan_id', 'modul_id', 'soal_id')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('praktikan_id', 'modul_id', 'soal_id')
            ->havingRaw('count(*) > 1')
            ->orderBy('praktikan_id')
            ->orderBy('modul_id')
            ->orderBy('soal_id')
            ->get();
    }

    /**
     * Archive the losing rows of one duplicate group.
     *
     * @param  object{praktikan_id: int, modul_id: int, soal_id: int}  $group
     * @return int|null Number of archived rows, or null when the group is ambiguous.
     */
    protected function processGroup(ConnectionInterface $source, string $table, string $tipe, object $group, bool $dryRun): ?int
    {
        $rows = $source->table($table)
            ->where('praktikan_id', $group->praktikan_id)
            ->where('modul_id', $group->modul_id)
            ->where('soal_id', $group->soal_id)
            ->orderBy('id')
            ->get();

        if ($this->hasIdenticalCreatedAt($rows)) {
            return null;
        }

        $winnerId = (int) $rows->max('id');
        $losers = $rows->reject(fn (object $row): bool => (int) $row->id === $winnerId)->values();

        if ($losers->isEmpty()) {
            return 0;
        }

        if ($dryRun) {
            $this->line(sprintf(
                '[dry-run] %s: slot (%d, %d, %d) -> pemenang id %d, kandidat arsip %s',
                $table,
                $group->praktikan_id,
                $group->modul_id,
                $group->soal_id,
                $winnerId,
                $losers->pluck('id')->implode(', ')
            ));

            return $losers->count();
        }

        try {
            $archived = $source->transaction(function () use ($source, $table, $tipe, $losers): int {
                $count = 0;
                $now = now();

                foreach ($losers as $row) {
                    $inserted = $source->table('jawaban_duplicates_archive')
                        ->insertOrIgnore([
                            'source_table' => $table,
                            'source_id' => (int) $row->id,
                            'tipe' => $tipe,
                            'praktikan_id' => (int) $row->praktikan_id,
                            'modul_id' => (int) $row->modul_id,
                            'soal_id' => (int) $row->soal_id,
                            'payload' => json_encode($row, JSON_THROW_ON_ERROR),
                            'archived_reason' => self::ARCHIVED_REASON,
                            'archived_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                    $source->table($table)->where('id', $row->id)->delete();

                    $count += $inserted;
                }

                return $count;
            });
        } catch (Throwable $exception) {
            $this->error(sprintf('%s: gagal memproses slot (%d, %d, %d) - %s', $table, $group->praktikan_id, $group->modul_id, $group->soal_id, $exception->getMessage()));

            throw $exception;
        }

        if ($archived > 0) {
            $this->line(sprintf(
                '%s: slot (%d, %d, %d) -> pemenang id %d, diarsipkan %d baris',
                $table,
                $group->praktikan_id,
                $group->modul_id,
                $group->soal_id,
                $winnerId,
                $archived
            ));
        }

        return $archived;
    }

    /**
     * A group is ambiguous as soon as any created_at value repeats inside it,
     * even when other rows of the group carry distinct timestamps.
     *
     * @param  Collection<int, object>  $rows
     */
    protected function hasIdenticalCreatedAt(Collection $rows): bool
    {
        $timestamps = $rows
            ->map(fn (object $row): string => (string) ($row->created_at ?? ''))
            ->values();

        return $timestamps->count() !== $timestamps->unique()->count();
    }

    /**
     * @param  object{praktikan_id: int, modul_id: int, soal_id: int}  $group
     */
    protected function describeAmbiguousGroup(string $table, object $group): string
    {
        return sprintf(
            '%s: slot (praktikan_id=%d, modul_id=%d, soal_id=%d) memiliki created_at identik',
            $table,
            $group->praktikan_id,
            $group->modul_id,
            $group->soal_id
        );
    }
}
