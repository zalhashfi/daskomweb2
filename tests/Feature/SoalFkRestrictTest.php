<?php

namespace Tests\Feature;

use App\Models\JawabanMandiri;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalMandiri;
use App\Models\SoalTp;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards the soft-delete + ON DELETE RESTRICT migration
 * (2026_10_17_100000_soft_delete_soal_and_restrict_jawaban_fk).
 *
 * The suite's RefreshDatabase runs every migration, so the post-up() shape is
 * the starting point; down()/up() are re-driven by loading the migration file,
 * mirroring LegacyMigrationRollbackTest.
 */
class SoalFkRestrictTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_17_100000_soft_delete_soal_and_restrict_jawaban_fk';

    /**
     * Answer table => [referenced soal table, foreign key name].
     */
    private const FOREIGN_KEYS = [
        'jawaban_tps' => ['soal_tps', 'jawaban_tps_soal_id_foreign'],
        'jawaban_mandiris' => ['soal_mandiris', 'jawaban_mandiris_soal_id_foreign'],
    ];

    private const SOAL_TABLES = ['soal_tps', 'soal_mandiris'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->isMySqlFamily()) {
            $this->markTestSkipped('FK delete-rule assertions are MySQL/MariaDB specific.');
        }
    }

    public function test_soft_delete_columns_exist_on_both_soal_tables(): void
    {
        $this->assertTrue(Schema::hasColumn('soal_tps', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('soal_mandiris', 'deleted_at'));
    }

    public function test_soal_foreign_keys_use_restrict_after_migration(): void
    {
        foreach (self::FOREIGN_KEYS as $table => [$referenced, $foreignKey]) {
            $this->assertDeleteRule($table, $foreignKey, 'RESTRICT');
            $this->assertSame($referenced, $this->foreignKeyTarget($table, $foreignKey));
        }
    }

    public function test_hard_deleting_soal_tp_with_answers_is_rejected(): void
    {
        $modul = Modul::factory()->create();
        $soal = SoalTp::create([
            'modul_id' => $modul->id,
            'soal' => 'Soal TP dengan jawaban.',
        ]);
        $jawaban = $this->createJawabanTp($soal, $modul);

        $exception = $this->captureQueryException(
            fn () => DB::table('soal_tps')->where('id', $soal->id)->delete()
        );

        $this->assertForeignKeyViolation($exception);
        $this->assertDatabaseHas('soal_tps', ['id' => $soal->id]);
        $this->assertDatabaseHas('jawaban_tps', ['id' => $jawaban->id]);
    }

    public function test_hard_deleting_soal_mandiri_with_answers_is_rejected(): void
    {
        $modul = Modul::factory()->create();
        $soal = SoalMandiri::create([
            'modul_id' => $modul->id,
            'soal' => 'Soal mandiri dengan jawaban.',
        ]);
        $jawaban = $this->createJawabanMandiri($soal, $modul);

        $exception = $this->captureQueryException(
            fn () => DB::table('soal_mandiris')->where('id', $soal->id)->delete()
        );

        $this->assertForeignKeyViolation($exception);
        $this->assertDatabaseHas('soal_mandiris', ['id' => $soal->id]);
        $this->assertDatabaseHas('jawaban_mandiris', ['id' => $jawaban->id]);
    }

    public function test_soft_delete_still_succeeds_while_answers_exist(): void
    {
        $modul = Modul::factory()->create();

        $soalTp = SoalTp::create(['modul_id' => $modul->id, 'soal' => 'Soal TP soft delete.']);
        $this->createJawabanTp($soalTp, $modul);

        $soalMandiri = SoalMandiri::create(['modul_id' => $modul->id, 'soal' => 'Soal mandiri soft delete.']);
        $this->createJawabanMandiri($soalMandiri, $modul);

        $this->assertTrue($soalTp->delete(), 'Soft delete SoalTp harus sukses walau ada jawaban.');
        $this->assertTrue($soalMandiri->delete(), 'Soft delete SoalMandiri harus sukses walau ada jawaban.');

        $this->assertSoftDeleted('soal_tps', ['id' => $soalTp->id]);
        $this->assertSoftDeleted('soal_mandiris', ['id' => $soalMandiri->id]);
        $this->assertSame(1, JawabanTp::where('soal_id', $soalTp->id)->count());
        $this->assertSame(1, JawabanMandiri::where('soal_id', $soalMandiri->id)->count());
    }

    public function test_migration_down_restores_cascade_and_up_reapplies_restrict(): void
    {
        $this->runMigration('down');

        foreach (self::SOAL_TABLES as $table) {
            $this->assertFalse(
                Schema::hasColumn($table, 'deleted_at'),
                "down() harus menghapus kolom {$table}.deleted_at."
            );
        }

        foreach (self::FOREIGN_KEYS as $table => [, $foreignKey]) {
            $this->assertDeleteRule($table, $foreignKey, 'CASCADE');
        }

        $this->runMigration('up');

        foreach (self::SOAL_TABLES as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'deleted_at'),
                "up() harus menambahkan kembali kolom {$table}.deleted_at."
            );
        }

        foreach (self::FOREIGN_KEYS as $table => [$referenced, $foreignKey]) {
            $this->assertDeleteRule($table, $foreignKey, 'RESTRICT');
            $this->assertSame($referenced, $this->foreignKeyTarget($table, $foreignKey));
        }
    }

    public function test_migration_up_and_down_are_idempotent(): void
    {
        // up() twice: no duplicate column, FK stays RESTRICT.
        $this->runMigration('up');
        $this->runMigration('up');

        $this->assertSame(2, $this->deletedAtColumnCount());

        foreach (self::FOREIGN_KEYS as $table => [, $foreignKey]) {
            $this->assertDeleteRule($table, $foreignKey, 'RESTRICT');
        }

        // down() twice: columns gone, FK back to CASCADE.
        $this->runMigration('down');
        $this->runMigration('down');

        $this->assertSame(0, $this->deletedAtColumnCount());

        foreach (self::FOREIGN_KEYS as $table => [, $foreignKey]) {
            $this->assertDeleteRule($table, $foreignKey, 'CASCADE');
        }

        // Leave the schema in its migrated shape for the rest of the suite.
        $this->runMigration('up');

        foreach (self::FOREIGN_KEYS as $table => [, $foreignKey]) {
            $this->assertDeleteRule($table, $foreignKey, 'RESTRICT');
        }
    }

    private function isMySqlFamily(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function createJawabanTp(SoalTp $soal, Modul $modul): JawabanTp
    {
        $praktikanId = Praktikan::factory()->create()->id;

        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikanId,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
        ]);

        return JawabanTp::create([
            'praktikan_id' => $praktikanId,
            'soal_id' => $soal->id,
            'modul_id' => $modul->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Jawaban praktikan untuk soal TP.',
        ]);
    }

    private function createJawabanMandiri(SoalMandiri $soal, Modul $modul): JawabanMandiri
    {
        $praktikanId = Praktikan::factory()->create()->id;

        // jawaban_mandiris.submission_id is NOT NULL (FK to jawaban_submissions).
        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikanId,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TM,
        ]);

        return JawabanMandiri::create([
            'praktikan_id' => $praktikanId,
            'soal_id' => $soal->id,
            'modul_id' => $modul->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Jawaban praktikan untuk soal mandiri.',
        ]);
    }

    private function captureQueryException(callable $callback): QueryException
    {
        try {
            $callback();
        } catch (QueryException $exception) {
            return $exception;
        }

        $this->fail('Expected the database to reject the hard delete via the RESTRICT foreign key.');
    }

    private function assertForeignKeyViolation(QueryException $exception): void
    {
        $this->assertSame('23000', $exception->getCode(), 'SQLSTATE harus menandakan pelanggaran integritas.');
        $this->assertStringContainsString('foreign key constraint', strtolower($exception->getMessage()));
        $this->assertStringContainsString('1451', $exception->getMessage(), 'MySQL harus mengembalikan error 1451.');
    }

    private function assertDeleteRule(string $table, string $foreignKey, string $expectedRule): void
    {
        $row = $this->foreignKeyMetadata($table)
            ->firstWhere('CONSTRAINT_NAME', $foreignKey);

        $this->assertNotNull(
            $row,
            "Foreign key {$foreignKey} harus ada pada tabel {$table}."
        );
        $this->assertSame(
            $expectedRule,
            strtoupper((string) $row->DELETE_RULE),
            "Aturan ON DELETE {$foreignKey} harus {$expectedRule}."
        );
    }

    private function foreignKeyTarget(string $table, string $foreignKey): ?string
    {
        return $this->foreignKeyMetadata($table)
            ->firstWhere('CONSTRAINT_NAME', $foreignKey)?->REFERENCED_TABLE_NAME;
    }

    private function foreignKeyMetadata(string $table): Collection
    {
        return collect(DB::select(
            'select rc.CONSTRAINT_NAME, rc.DELETE_RULE, k.REFERENCED_TABLE_NAME
             from information_schema.REFERENTIAL_CONSTRAINTS rc
             join information_schema.KEY_COLUMN_USAGE k
               on k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
              and k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
              and k.TABLE_NAME = rc.TABLE_NAME
             where rc.CONSTRAINT_SCHEMA = database() and rc.TABLE_NAME = ?',
            [$table]
        ));
    }

    private function deletedAtColumnCount(): int
    {
        return collect(DB::select(
            'select count(*) as total from information_schema.COLUMNS
             where table_schema = database() and column_name = ? and table_name in (?, ?)',
            ['deleted_at', 'soal_tps', 'soal_mandiris']
        ))->sum(fn ($row) => (int) $row->total);
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
