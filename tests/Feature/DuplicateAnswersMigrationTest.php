<?php

namespace Tests\Feature;

use App\Console\Commands\ArchiveDuplicateAnswers;
use App\Models\JawabanSubmission;
use App\Models\Praktikan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers the jawaban_duplicates_archive migration and the
 * `jawaban:archive-duplicates` command.
 *
 * The command is driven through Artisan so the exit code, the unique archive
 * index and the deletion of losing rows are all exercised end to end.
 */
class DuplicateAnswersMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, array{source: string, tipe: string, soal: string}>
     */
    private array $tables = [
        'tp' => ['source' => 'jawaban_tps', 'tipe' => 'tp', 'soal' => 'soal_tps'],
        'tm' => ['source' => 'jawaban_mandiris', 'tipe' => 'tm', 'soal' => 'soal_mandiris'],
    ];

    public function test_migration_creates_archive_table_with_expected_shape(): void
    {
        $this->assertTrue(Schema::hasTable('jawaban_duplicates_archive'));

        foreach ([
            'id',
            'source_table',
            'source_id',
            'tipe',
            'praktikan_id',
            'modul_id',
            'soal_id',
            'payload',
            'archived_reason',
            'archived_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('jawaban_duplicates_archive', $column),
                "Kolom jawaban_duplicates_archive.{$column} harus ada."
            );
        }
    }

    public function test_jawaban_tps_differing_created_at_archives_oldest_row(): void
    {
        [$praktikanId, $modulId, $soalId] = $this->makeSlot('tp');

        $loserId = $this->insertJawaban('tp', $praktikanId, $modulId, $soalId, '2026-10-01 08:00:00');
        $winnerId = $this->insertJawaban('tp', $praktikanId, $modulId, $soalId, '2026-10-01 09:00:00');

        $this->assertSame(2, $this->sourceCount('tp'));

        $exitCode = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, DB::table('jawaban_duplicates_archive')->count());
        $this->assertSame(1, $this->sourceCount('tp'));
        $this->assertDatabaseHas('jawaban_tps', ['id' => $winnerId]);
        $this->assertDatabaseMissing('jawaban_tps', ['id' => $loserId]);

        $archived = DB::table('jawaban_duplicates_archive')->first();

        $this->assertSame('jawaban_tps', $archived->source_table);
        $this->assertSame($loserId, (int) $archived->source_id);
        $this->assertSame('tp', $archived->tipe);
        $this->assertSame($praktikanId, (int) $archived->praktikan_id);
        $this->assertSame($modulId, (int) $archived->modul_id);
        $this->assertSame($soalId, (int) $archived->soal_id);
        $this->assertSame(ArchiveDuplicateAnswers::ARCHIVED_REASON, $archived->archived_reason);
        $this->assertNotNull($archived->archived_at);

        $payload = json_decode((string) $archived->payload, true);

        $this->assertIsArray($payload);
        $this->assertSame($loserId, (int) $payload['id']);
        $this->assertSame('Jawaban lama 2026-10-01 08:00:00', $payload['jawaban']);
    }

    public function test_jawaban_mandiris_differing_created_at_archives_oldest_row(): void
    {
        [$praktikanId, $modulId, $soalId] = $this->makeSlot('tm');

        $loserId = $this->insertJawaban('tm', $praktikanId, $modulId, $soalId, '2026-10-02 08:00:00');
        $winnerId = $this->insertJawaban('tm', $praktikanId, $modulId, $soalId, '2026-10-02 10:00:00');

        $exitCode = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, DB::table('jawaban_duplicates_archive')->count());
        $this->assertSame(1, $this->sourceCount('tm'));
        $this->assertDatabaseHas('jawaban_mandiris', ['id' => $winnerId]);
        $this->assertDatabaseMissing('jawaban_mandiris', ['id' => $loserId]);
        $this->assertDatabaseHas('jawaban_duplicates_archive', [
            'source_table' => 'jawaban_mandiris',
            'source_id' => $loserId,
            'tipe' => 'tm',
        ]);
    }

    public function test_max_id_wins_even_when_it_holds_the_older_timestamp(): void
    {
        [$praktikanId, $modulId, $soalId] = $this->makeSlot('tp');

        $loserId = $this->insertJawaban('tp', $praktikanId, $modulId, $soalId, '2026-10-03 08:00:00');
        $winnerId = $this->insertJawaban('tp', $praktikanId, $modulId, $soalId, '2026-10-03 12:00:00');

        $this->assertGreaterThan($loserId, $winnerId);

        $this->assertSame(0, $this->runCommand());

        $this->assertDatabaseHas('jawaban_tps', ['id' => $winnerId]);
        $this->assertDatabaseMissing('jawaban_tps', ['id' => $loserId]);
        $this->assertDatabaseHas('jawaban_duplicates_archive', [
            'source_table' => 'jawaban_tps',
            'source_id' => $loserId,
        ]);
    }

    public function test_identical_created_at_exits_one_and_leaves_both_rows(): void
    {
        [$praktikanId, $modulId, $soalId] = $this->makeSlot('tp');

        $firstId = $this->insertJawaban('tp', $praktikanId, $modulId, $soalId, '2026-10-04 08:00:00');
        $secondId = $this->insertJawaban('tp', $praktikanId, $modulId, $soalId, '2026-10-04 08:00:00');

        $exitCode = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, DB::table('jawaban_duplicates_archive')->count());
        $this->assertSame(2, $this->sourceCount('tp'));
        $this->assertDatabaseHas('jawaban_tps', ['id' => $firstId]);
        $this->assertDatabaseHas('jawaban_tps', ['id' => $secondId]);
    }

    public function test_identical_created_at_in_jawaban_mandiris_exits_one(): void
    {
        [$praktikanId, $modulId, $soalId] = $this->makeSlot('tm');

        $firstId = $this->insertJawaban('tm', $praktikanId, $modulId, $soalId, '2026-10-05 08:00:00');
        $secondId = $this->insertJawaban('tm', $praktikanId, $modulId, $soalId, '2026-10-05 08:00:00');

        $this->assertSame(1, $this->runCommand());
        $this->assertSame(0, DB::table('jawaban_duplicates_archive')->count());
        $this->assertSame(2, $this->sourceCount('tm'));
        $this->assertDatabaseHas('jawaban_mandiris', ['id' => $firstId]);
        $this->assertDatabaseHas('jawaban_mandiris', ['id' => $secondId]);
    }

    public function test_ambiguous_group_does_not_block_resolvable_groups(): void
    {
        $tpAmbiguous = $this->makeSlot('tp');
        $tpResolvable = $this->makeSlot('tp');

        $this->insertJawaban('tp', ...$tpAmbiguous, createdAt: '2026-10-06 08:00:00');
        $this->insertJawaban('tp', ...$tpAmbiguous, createdAt: '2026-10-06 08:00:00');

        $this->insertJawaban('tp', ...$tpResolvable, createdAt: '2026-10-06 08:00:00');
        $this->insertJawaban('tp', ...$tpResolvable, createdAt: '2026-10-06 09:00:00');

        $this->assertSame(1, $this->runCommand());

        $this->assertSame(1, DB::table('jawaban_duplicates_archive')->count());
        $this->assertSame(3, $this->sourceCount('tp'), 'Grup ambigu tetap utuh, grup lain berkurang satu.');
    }

    public function test_running_twice_is_idempotent(): void
    {
        [$praktikanId, $modulId, $soalId] = $this->makeSlot('tp');

        $this->insertJawaban('tp', $praktikanId, $modulId, $soalId, '2026-10-07 08:00:00');
        $this->insertJawaban('tp', $praktikanId, $modulId, $soalId, '2026-10-07 09:00:00');

        $this->assertSame(0, $this->runCommand());
        $this->assertSame(1, DB::table('jawaban_duplicates_archive')->count());

        $this->assertSame(0, $this->runCommand());
        $this->assertSame(1, DB::table('jawaban_duplicates_archive')->count());
        $this->assertSame(1, $this->sourceCount('tp'));
    }

    public function test_already_archived_rows_are_not_duplicated_on_rerun(): void
    {
        [$praktikanId, $modulId, $soalId] = $this->makeSlot('tm');

        $loserId = $this->insertJawaban('tm', $praktikanId, $modulId, $soalId, '2026-10-08 08:00:00');
        $this->insertJawaban('tm', $praktikanId, $modulId, $soalId, '2026-10-08 09:00:00');

        $this->assertSame(0, $this->runCommand());

        $this->insertJawaban('tm', $praktikanId, $modulId, $soalId, '2026-10-08 10:00:00');

        $this->assertSame(0, $this->runCommand());
        $this->assertSame(1, DB::table('jawaban_duplicates_archive')->where('source_id', $loserId)->count());
        $this->assertSame(2, DB::table('jawaban_duplicates_archive')->count(), 'Baris yang sudah diarsipkan tidak diarsipkan ulang.');
    }

    public function test_dry_run_reports_without_changing_data(): void
    {
        [$praktikanId, $modulId, $soalId] = $this->makeSlot('tp');

        $loserId = $this->insertJawaban('tp', $praktikanId, $modulId, $soalId, '2026-10-09 08:00:00');
        $winnerId = $this->insertJawaban('tp', $praktikanId, $modulId, $soalId, '2026-10-09 09:00:00');

        $exitCode = Artisan::call(ArchiveDuplicateAnswers::class, ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('dry-run', Artisan::output());
        $this->assertSame(0, DB::table('jawaban_duplicates_archive')->count());
        $this->assertSame(2, $this->sourceCount('tp'));
        $this->assertDatabaseHas('jawaban_tps', ['id' => $loserId]);
        $this->assertDatabaseHas('jawaban_tps', ['id' => $winnerId]);
    }

    public function test_groups_without_duplicates_are_left_untouched(): void
    {
        [$praktikanId, $modulId, $soalId] = $this->makeSlot('tp');

        $id = $this->insertJawaban('tp', $praktikanId, $modulId, $soalId, '2026-10-10 08:00:00');

        $this->assertSame(0, $this->runCommand());
        $this->assertSame(0, DB::table('jawaban_duplicates_archive')->count());
        $this->assertDatabaseHas('jawaban_tps', ['id' => $id]);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function makeSlot(string $key): array
    {
        $praktikanId = Praktikan::factory()->create()->id;

        $modulId = DB::table('moduls')->insertGetId([
            'judul' => 'Modul '.$key,
            'deskripsi' => 'Deskripsi '.$key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $soalId = DB::table($this->tables[$key]['soal'])->insertGetId([
            'modul_id' => $modulId,
            'soal' => 'Soal '.$key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$praktikanId, $modulId, $soalId];
    }

    private function insertJawaban(string $key, int $praktikanId, int $modulId, int $soalId, string $createdAt): int
    {
        // jawaban_tps.submission_id is NOT NULL and unique per (submission_id,
        // soal_id): identical answers on the same soal are two attempts, so
        // each duplicate row is attached to its own tp submission.
        $attributes = [
            'praktikan_id' => $praktikanId,
            'modul_id' => $modulId,
            'soal_id' => $soalId,
            'jawaban' => 'Jawaban lama '.$createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];

        if ($key === 'tp') {
            $attributes['submission_id'] = $this->makeTpSubmission($praktikanId, $modulId)->id;
        }

        return (int) DB::table($this->tables[$key]['source'])->insertGetId($attributes);
    }

    private function makeTpSubmission(int $praktikanId, int $modulId): JawabanSubmission
    {
        // (praktikan_id, modul_id, tipe, attempt_no) is unique, so every extra
        // answer in the same slot becomes the next attempt.
        $nextAttempt = (int) DB::table('jawaban_submissions')
            ->where('praktikan_id', $praktikanId)
            ->where('modul_id', $modulId)
            ->where('tipe', JawabanSubmission::TIPE_TP)
            ->max('attempt_no') + 1;

        return JawabanSubmission::create([
            'praktikan_id' => $praktikanId,
            'modul_id' => $modulId,
            'tipe' => JawabanSubmission::TIPE_TP,
            'attempt_no' => $nextAttempt,
        ])->fresh();
    }

    private function sourceCount(string $key): int
    {
        return DB::table($this->tables[$key]['source'])->count();
    }

    private function runCommand(): int
    {
        return Artisan::call(ArchiveDuplicateAnswers::class);
    }
}
