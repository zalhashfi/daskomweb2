<?php

namespace Tests\Feature;

use App\Models\JawabanMandiri;
use App\Models\JawabanSubmission;
use App\Models\Modul;
use App\Models\Praktikan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers 2026_10_17_170000_add_submission_id_to_jawaban_mandiris_table (T08b
 * pattern applied to jawaban_mandiris with tipe='tm'): the nullable column, the
 * (praktikan_id, modul_id) submission backfill, the NOT NULL + FK + unique
 * constraints and the JawabanMandiri::submission() relation.
 *
 * RefreshDatabase already ran every migration, so the post-up() shape is the
 * starting point. Because the suite runs on an empty database, the backfill is
 * exercised the way a real deploy would hit it: drop the schema pieces with
 * down(), re-insert legacy answer rows (no submission_id), then re-run up().
 */
class JawabanTmBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_17_170000_add_submission_id_to_jawaban_mandiris_table';

    private const UNIQUE_INDEX = 'jawaban_mandiris_submission_id_soal_id_unique';

    private const FOREIGN_KEY = 'jawaban_mandiris_submission_id_foreign';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    public function test_column_is_not_nullable_with_fk_and_unique_index(): void
    {
        $this->assertTrue(
            Schema::hasColumn('jawaban_mandiris', 'submission_id'),
            'Kolom jawaban_mandiris.submission_id harus ada.'
        );

        $column = $this->columnInfo('jawaban_mandiris', 'submission_id');
        $this->assertSame('NO', $column->IS_NULLABLE, 'Kolom submission_id harus NOT NULL.');

        $this->assertContains(
            self::FOREIGN_KEY,
            $this->foreignKeyNames('jawaban_mandiris'),
            'Foreign key submission_id harus terdaftar.'
        );

        $this->assertSame('jawaban_submissions', $this->foreignKeyTarget(self::FOREIGN_KEY));
        $this->assertSame('CASCADE', $this->foreignKeyDeleteRule(self::FOREIGN_KEY));

        $this->assertSame(
            ['submission_id', 'soal_id'],
            $this->uniqueIndexColumns('jawaban_mandiris', self::UNIQUE_INDEX)
        );
    }

    public function test_backfill_creates_one_tm_submission_per_unique_pair(): void
    {
        $praktikanA = Praktikan::factory()->create();
        $praktikanB = Praktikan::factory()->create();
        $modulA = Modul::factory()->create();
        $modulB = Modul::factory()->create();
        $soalA1 = $this->createSoalMandiri($modulA);
        $soalA2 = $this->createSoalMandiri($modulA);
        $soalB = $this->createSoalMandiri($modulB);

        $this->runMigration('down');

        $this->insertLegacyAnswer($praktikanA, $modulA, $soalA1, '2026-01-05 08:00:00');
        $this->insertLegacyAnswer($praktikanA, $modulA, $soalA2, '2026-01-04 10:30:00');
        $this->insertLegacyAnswer($praktikanA, $modulB, $soalB, '2026-02-01 09:00:00');
        $this->insertLegacyAnswer($praktikanB, $modulA, $soalA1, '2026-03-01 09:00:00');

        $this->runMigration('up');

        // Count the distinct groups from the returned rows: Builder::count()
        // drops the DISTINCT flag in this Laravel build, so GROUP BY + rows is
        // used instead of ->distinct()->count().
        $uniquePairs = DB::table('jawaban_mandiris')
            ->select('praktikan_id', 'modul_id')
            ->groupBy('praktikan_id', 'modul_id')
            ->get()
            ->count();

        $tmSubmissions = DB::table('jawaban_submissions')
            ->where('tipe', JawabanSubmission::TIPE_TM)
            ->count();

        $this->assertSame(4, DB::table('jawaban_mandiris')->count(), 'Data uji harus memuat empat jawaban.');
        $this->assertSame(3, $uniquePairs, 'Data uji harus memuat tiga pasangan unik.');
        $this->assertSame(
            $uniquePairs,
            $tmSubmissions,
            'Jumlah submission tm harus sama dengan jumlah pasangan unik.'
        );

        $this->assertSame(
            0,
            DB::table('jawaban_mandiris')->whereNull('submission_id')->count(),
            'Tidak boleh ada jawaban_mandiris dengan submission_id NULL.'
        );

        // Every answer points at a tm submission of the same praktikan/modul.
        $mismatched = DB::table('jawaban_mandiris as jm')
            ->join('jawaban_submissions as js', 'js.id', '=', 'jm.submission_id')
            ->where(function ($query) {
                $query->where('js.tipe', '!=', JawabanSubmission::TIPE_TM)
                    ->orWhereColumn('js.praktikan_id', '!=', 'jm.praktikan_id')
                    ->orWhereColumn('js.modul_id', '!=', 'jm.modul_id');
            })
            ->count();

        $this->assertSame(0, $mismatched, 'Setiap submission_id harus cocok tipe/praktikan/modul.');

        $submissionA = $this->submissionFor($praktikanA, $modulA);
        $this->assertNull($submissionA->jawaban_id);
        $this->assertSame(1, (int) $submissionA->attempt_no);
        $this->assertSame('2026-01-04 10:30:00', (string) $submissionA->created_at);

        $this->assertSame(
            [$submissionA->id],
            DB::table('jawaban_mandiris')
                ->where('praktikan_id', $praktikanA->id)
                ->where('modul_id', $modulA->id)
                ->distinct()
                ->pluck('submission_id')
                ->all(),
            'Semua jawaban pada satu pasangan harus memakai submission yang sama.'
        );
    }

    public function test_backfill_does_not_mix_tp_submissions(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $soal = $this->createSoalMandiri($modul);

        // A tp submission already exists for the same praktikan/modul pair; the
        // tm backfill must create its own row instead of reusing it.
        $tpSubmission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
            'attempt_no' => 1,
        ])->fresh();

        $this->runMigration('down');
        $this->insertLegacyAnswer($praktikan, $modul, $soal, '2026-05-01 08:00:00');
        $this->runMigration('up');

        $tmSubmission = $this->submissionFor($praktikan, $modul);

        $this->assertNotNull($tmSubmission, 'Backfill harus membuat submission tm.');
        $this->assertNotSame($tpSubmission->id, (int) $tmSubmission->id);

        $stamped = DB::table('jawaban_mandiris')->where('soal_id', $soal)->value('submission_id');
        $this->assertSame((int) $tmSubmission->id, (int) $stamped, 'Baris tm tidak boleh menunjuk submission tp.');

        $this->assertSame(
            1,
            DB::table('jawaban_submissions')
                ->where('praktikan_id', $praktikan->id)
                ->where('modul_id', $modul->id)
                ->where('tipe', JawabanSubmission::TIPE_TP)
                ->count(),
            'Submission tp yang sudah ada tidak boleh berubah/diduplikasi.'
        );
    }

    public function test_backfill_is_idempotent(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $soal = $this->createSoalMandiri($modul);

        $this->runMigration('down');

        // A submission already exists for this pair (partial migration state);
        // the backfill must reuse it instead of inserting a second row.
        $existing = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TM,
            'attempt_no' => 1,
        ]);

        $this->insertLegacyAnswer($praktikan, $modul, $soal, '2026-05-01 08:00:00');

        $this->runMigration('up');

        $this->assertSame(
            1,
            DB::table('jawaban_submissions')
                ->where('praktikan_id', $praktikan->id)
                ->where('modul_id', $modul->id)
                ->where('tipe', JawabanSubmission::TIPE_TM)
                ->where('attempt_no', 1)
                ->count(),
            'Backfill harus memakai submission tm yang sudah ada (insertOrIgnore).'
        );

        $this->assertSame(
            $existing->id,
            (int) DB::table('jawaban_mandiris')->where('soal_id', $soal)->value('submission_id'),
            'Baris yang sudah ada harus di-stamp dengan submission yang telah ada.'
        );
    }

    public function test_backfill_falls_back_to_now_when_created_at_is_null(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $soal = $this->createSoalMandiri($modul);

        $this->runMigration('down');

        $this->insertLegacyAnswer($praktikan, $modul, $soal, null);

        $this->runMigration('up');

        $submission = $this->submissionFor($praktikan, $modul);

        $this->assertNotNull($submission->created_at, 'created_at harus jatuh ke now() bila null.');
        $this->assertEqualsWithDelta(
            now()->getTimestamp(),
            strtotime((string) $submission->created_at),
            120,
            'created_at fallback harus mendekati waktu migrasi.'
        );
        $this->assertNotNull(
            DB::table('jawaban_mandiris')->where('soal_id', $soal)->value('submission_id')
        );
    }

    public function test_duplicate_soal_within_one_submission_is_rejected(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $soalId = $this->createSoalMandiri($modul);

        $submission = $this->makeSubmission($praktikan, $modul);

        JawabanMandiri::create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $soalId,
            'modul_id' => $modul->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Percobaan pertama.',
        ]);

        $exception = $this->captureQueryException(fn () => JawabanMandiri::create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $soalId,
            'modul_id' => $modul->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Duplikat.',
        ]));

        $this->assertSame('23000', $exception->getCode(), 'SQLSTATE harus menandakan unique violation.');
        $this->assertStringContainsString('1062', $exception->getMessage(), 'MySQL harus mengembalikan error 1062.');
        $this->assertSame(1, JawabanMandiri::query()->where('soal_id', $soalId)->count());
    }

    public function test_submission_relation_returns_matching_model(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $soalId = $this->createSoalMandiri($modul);

        $submission = $this->makeSubmission($praktikan, $modul);

        $jawaban = JawabanMandiri::create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $soalId,
            'modul_id' => $modul->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Jawaban relasi.',
        ]);

        $related = $jawaban->fresh()->submission;

        $this->assertInstanceOf(JawabanSubmission::class, $related);
        $this->assertSame($submission->id, $related->id);
        $this->assertSame(JawabanSubmission::TIPE_TM, $related->tipe);
        $this->assertSame($praktikan->id, $related->praktikan_id);
        $this->assertSame($modul->id, $related->modul_id);
    }

    public function test_answer_rows_are_removed_when_submission_is_deleted(): void
    {
        if (! $this->isMySqlFamily()) {
            $this->markTestSkipped('Cascade assertions are MySQL/MariaDB specific.');
        }

        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $submission = $this->makeSubmission($praktikan, $modul);

        $jawaban = JawabanMandiri::create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $this->createSoalMandiri($modul),
            'modul_id' => $modul->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Akan terhapus.',
        ]);

        DB::table('jawaban_submissions')->where('id', $submission->id)->delete();

        $this->assertDatabaseMissing('jawaban_mandiris', ['id' => $jawaban->id]);
    }

    public function test_migration_down_then_up_restores_schema_and_backfill(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $soalA = $this->createSoalMandiri($modul);
        $soalB = $this->createSoalMandiri($modul);

        $this->runMigration('down');

        $this->assertFalse(
            Schema::hasColumn('jawaban_mandiris', 'submission_id'),
            'down() harus menghapus kolom submission_id.'
        );

        $this->insertLegacyAnswer($praktikan, $modul, $soalA, '2026-04-01 07:00:00');
        $this->insertLegacyAnswer($praktikan, $modul, $soalB, '2026-04-02 07:00:00');

        $this->runMigration('up');

        $this->assertTrue(Schema::hasColumn('jawaban_mandiris', 'submission_id'), 'up() harus mengembalikan kolom.');
        $column = $this->columnInfo('jawaban_mandiris', 'submission_id');
        $this->assertSame('NO', $column->IS_NULLABLE, 'up() harus membuat kolom NOT NULL lagi.');
        $this->assertContains(self::FOREIGN_KEY, $this->foreignKeyNames('jawaban_mandiris'));
        $this->assertSame(
            ['submission_id', 'soal_id'],
            $this->uniqueIndexColumns('jawaban_mandiris', self::UNIQUE_INDEX)
        );
        $this->assertSame(
            0,
            DB::table('jawaban_mandiris')->whereNull('submission_id')->count(),
            'up() kedua harus mengisi kembali semua submission_id.'
        );
        $this->assertNotNull($this->submissionFor($praktikan, $modul));
    }

    private function isMySqlFamily(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function makeSubmission(Praktikan $praktikan, Modul $modul): JawabanSubmission
    {
        return JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TM,
            'attempt_no' => 1,
        ])->fresh();
    }

    private function createSoalMandiri(Modul $modul): int
    {
        return DB::table('soal_mandiris')->insertGetId([
            'modul_id' => $modul->id,
            'soal' => 'Soal TM untuk backfill.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Insert an answer row straight into the table, as legacy data did: no
     * submission_id (the column does not exist after down()).
     */
    private function insertLegacyAnswer(Praktikan $praktikan, Modul $modul, int $soalId, ?string $createdAt): int
    {
        return DB::table('jawaban_mandiris')->insertGetId([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soalId,
            'jawaban' => 'Jawaban lama.',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function submissionFor(Praktikan $praktikan, Modul $modul): ?object
    {
        return DB::table('jawaban_submissions')
            ->where('praktikan_id', $praktikan->id)
            ->where('modul_id', $modul->id)
            ->where('tipe', JawabanSubmission::TIPE_TM)
            ->where('attempt_no', 1)
            ->first();
    }

    private function columnInfo(string $table, string $column): object
    {
        $row = DB::selectOne(
            'SELECT IS_NULLABLE, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        $this->assertNotNull($row, "Kolom {$table}.{$column} harus ada.");

        return $row;
    }

    /**
     * @return list<string>
     */
    private function foreignKeyNames(string $table): array
    {
        return array_map(
            fn (object $row): string => $row->CONSTRAINT_NAME,
            DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$table]
            )
        );
    }

    private function foreignKeyTarget(string $constraint): string
    {
        $row = DB::selectOne(
            'SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ? AND TABLE_NAME = ?',
            [$constraint, 'jawaban_mandiris']
        );

        $this->assertNotNull($row, "Foreign key {$constraint} harus ada.");

        return $row->REFERENCED_TABLE_NAME;
    }

    private function foreignKeyDeleteRule(string $constraint): string
    {
        $row = DB::selectOne(
            'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
            [$constraint]
        );

        $this->assertNotNull($row, "Foreign key {$constraint} harus punya referential constraint.");

        return $row->DELETE_RULE;
    }

    /**
     * @return list<string>
     */
    private function uniqueIndexColumns(string $table, string $index): array
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0 ORDER BY SEQ_IN_INDEX',
            [$table, $index]
        );

        return array_map(fn (object $row): string => $row->COLUMN_NAME, $rows);
    }

    private function captureQueryException(callable $callback): QueryException
    {
        try {
            $callback();
        } catch (QueryException $exception) {
            return $exception;
        }

        $this->fail('Database harus menolak duplikat (submission_id, soal_id).');
    }

    private function migrationPath(): string
    {
        $path = collect(glob(database_path('migrations/*.php')))
            ->first(fn (string $file): bool => str_contains(basename($file), self::MIGRATION));

        $this->assertNotNull($path, 'Migration '.self::MIGRATION.' not found.');

        return $path;
    }

    private function runMigration(string $direction): void
    {
        $migration = require $this->migrationPath();
        $migration->{$direction}();
    }
}
