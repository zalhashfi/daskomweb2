<?php

namespace Tests\Feature;

use App\Models\JawabanMandiri;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers the jawaban_submissions schema (2026_10_17_120000) and the
 * JawabanSubmission model: unique attempt slot, lock flag, relations and the
 * deterministic tipe-based jawaban() lookup.
 *
 * RefreshDatabase runs every migration, so the post-up() shape is the start;
 * down()/up() are re-driven by loading the migration file, mirroring
 * LegacyMigrationRollbackTest.
 */
class JawabanSubmissionModelTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_17_120000_create_jawaban_submissions_table';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    public function test_schema_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('jawaban_submissions'));

        foreach ([
            'id',
            'praktikan_id',
            'modul_id',
            'tipe',
            'attempt_no',
            'jawaban_id',
            'locked_at',
            'locked_by_type',
            'locked_by_id',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('jawaban_submissions', $column),
                "Kolom jawaban_submissions.{$column} harus ada."
            );
        }
    }

    public function test_duplicate_attempt_slot_is_rejected_by_unique_index(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();

        $attributes = [
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
            'attempt_no' => 1,
        ];

        JawabanSubmission::create($attributes);

        $exception = $this->captureQueryException(
            fn () => JawabanSubmission::create($attributes)
        );

        $this->assertSame('23000', $exception->getCode(), 'SQLSTATE harus menandakan unique violation.');
        $this->assertStringContainsString('1062', $exception->getMessage(), 'MySQL harus mengembalikan error 1062.');
        $this->assertSame(1, JawabanSubmission::query()->count());
    }

    public function test_different_attempt_no_is_allowed_for_same_slot(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();

        JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
        ]);
        $second = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
            'attempt_no' => 2,
        ]);

        $this->assertSame(2, $second->attempt_no);
        $this->assertSame(2, JawabanSubmission::query()->count());
    }

    public function test_attempt_no_and_locked_at_are_cast(): void
    {
        $submission = $this->makeSubmission(['attempt_no' => 3, 'locked_at' => '2026-10-17 10:00:00']);

        $this->assertSame(3, $submission->attempt_no);
        $this->assertInstanceOf(\Carbon\Carbon::class, $submission->locked_at);
        $this->assertSame('2026-10-17 10:00:00', $submission->locked_at->format('Y-m-d H:i:s'));
    }

    public function test_default_attempt_no_is_one(): void
    {
        $submission = $this->makeSubmission();

        $this->assertSame(1, $submission->fresh()->attempt_no);
    }

    public function test_is_locked_reflects_locked_at(): void
    {
        $unlocked = $this->makeSubmission();
        $this->assertFalse($unlocked->isLocked(), 'locked_at null berarti belum terkunci.');

        $locked = $this->makeSubmission([
            'locked_at' => now(),
            'locked_by_type' => 'asisten',
            'locked_by_id' => 99,
        ]);
        $this->assertTrue($locked->isLocked(), 'locked_at terisi berarti terkunci.');
    }

    public function test_praktikan_and_modul_relations(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TM,
        ])->fresh();

        $this->assertInstanceOf(Praktikan::class, $submission->praktikan);
        $this->assertSame($praktikan->id, $submission->praktikan->id);
        $this->assertInstanceOf(Modul::class, $submission->modul);
        $this->assertSame($modul->id, $submission->modul->id);
    }

    public function test_jawaban_returns_jawaban_tp_for_tipe_tp(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
        ]);
        $jawabanTp = JawabanTp::create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $this->createSoalTpId($modul),
            'modul_id' => $modul->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Jawaban TP.',
        ]);

        $submission->update(['jawaban_id' => $jawabanTp->id]);

        $resolved = $submission->fresh()->jawaban();

        $this->assertInstanceOf(JawabanTp::class, $resolved);
        $this->assertSame($jawabanTp->id, $resolved->id);
    }

    public function test_jawaban_returns_jawaban_mandiri_for_tipe_tm(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $jawabanMandiri = JawabanMandiri::create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $this->createSoalMandiriId($modul),
            'modul_id' => $modul->id,
            'jawaban' => 'Jawaban mandiri.',
        ]);

        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TM,
            'jawaban_id' => $jawabanMandiri->id,
        ]);

        $resolved = $submission->jawaban();

        $this->assertInstanceOf(JawabanMandiri::class, $resolved);
        $this->assertSame($jawabanMandiri->id, $resolved->id);
    }

    public function test_jawaban_is_null_without_jawaban_id(): void
    {
        $submission = $this->makeSubmission(['jawaban_id' => null]);

        $this->assertNull($submission->jawaban());
    }

    public function test_jawaban_is_null_for_unknown_tipe(): void
    {
        $submission = $this->makeSubmission(['tipe' => 'xx', 'jawaban_id' => 1]);

        $this->assertNull($submission->jawaban());
    }

    public function test_for_modul_scope_only_returns_matching_modul(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modulA = Modul::factory()->create();
        $modulB = Modul::factory()->create();

        JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modulA->id,
            'tipe' => JawabanSubmission::TIPE_TP,
        ]);
        JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modulB->id,
            'tipe' => JawabanSubmission::TIPE_TP,
        ]);

        $modulIds = JawabanSubmission::forModul($modulA->id)->pluck('modul_id');

        $this->assertCount(1, $modulIds);
        $this->assertSame([$modulA->id], $modulIds->all());
    }

    public function test_deleting_praktikan_cascades_submissions(): void
    {
        if (! $this->isMySqlFamily()) {
            $this->markTestSkipped('Cascade assertions are MySQL/MariaDB specific.');
        }

        $submission = $this->makeSubmission();

        DB::table('praktikans')->where('id', $submission->praktikan_id)->delete();

        $this->assertDatabaseMissing('jawaban_submissions', ['id' => $submission->id]);
    }

    public function test_migration_down_then_up_restores_table(): void
    {
        $this->assertTrue(Schema::hasTable('jawaban_submissions'));

        // jawaban_tps.submission_id references this table with a CASCADE
        // foreign key, so the migration under test runs its down() first to
        // drop that reference, exactly like a real `migrate:rollback` batch.
        $this->runSubmissionsReferenceMigration('down');

        $this->runMigration('down');

        $this->assertFalse(Schema::hasTable('jawaban_submissions'), 'down() harus menghapus tabel.');
        $this->assertTrue(Schema::hasTable('praktikans'), 'down() tidak boleh menyentuh tabel lain.');
        $this->assertTrue(Schema::hasTable('moduls'), 'down() tidak boleh menyentuh tabel lain.');

        $this->runMigration('up');
        $this->runSubmissionsReferenceMigration('up');

        $this->assertTrue(Schema::hasTable('jawaban_submissions'), 'up() harus membuat ulang tabel.');
        foreach (['praktikan_id', 'modul_id', 'tipe', 'attempt_no', 'jawaban_id', 'locked_at', 'locked_by_type', 'locked_by_id'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('jawaban_submissions', $column),
                "up() harus membuat kembali kolom {$column}."
            );
        }

        $submission = $this->makeSubmission();
        $this->assertSame(1, $submission->attempt_no);
        $this->assertFalse($submission->isLocked());
        $this->assertContains(
            'jawaban_tps_submission_id_foreign',
            array_map(
                fn (object $row): string => $row->CONSTRAINT_NAME,
                DB::select(
                    'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME = ?',
                    ['jawaban_tps', 'jawaban_submissions']
                )
            ),
            'FK jawaban_tps.submission_id harus kembali setelah up().'
        );
    }

    /**
     * Re-drive 2026_10_17_150000_add_submission_id_to_jawaban_tps_table, which
     * owns the foreign key that keeps jawaban_submissions from being dropped.
     */
    private function runSubmissionsReferenceMigration(string $direction): void
    {
        $migration = require database_path('migrations/2026_10_17_150000_add_submission_id_to_jawaban_tps_table.php');

        $migration->{$direction}();
    }

    private function isMySqlFamily(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSubmission(array $attributes = []): JawabanSubmission
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();

        return JawabanSubmission::create(array_merge([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
        ], $attributes))->fresh();
    }

    private function createSoalTpId(Modul $modul): int
    {
        return DB::table('soal_tps')->insertGetId([
            'modul_id' => $modul->id,
            'soal' => 'Soal TP untuk submission.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSoalMandiriId(Modul $modul): int
    {
        return DB::table('soal_mandiris')->insertGetId([
            'modul_id' => $modul->id,
            'soal' => 'Soal mandiri untuk submission.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function captureQueryException(callable $callback): QueryException
    {
        try {
            $callback();
        } catch (QueryException $exception) {
            return $exception;
        }

        $this->fail('Expected the database to reject the duplicate attempt slot.');
    }

    private function runMigration(string $direction): void
    {
        $path = collect(glob(database_path('migrations/*.php')))
            ->first(fn (string $file): bool => str_contains(basename($file), self::MIGRATION));

        $this->assertNotNull($path, 'Migration '.self::MIGRATION.' not found.');

        $migration = require $path;
        $migration->{$direction}();
    }
}
