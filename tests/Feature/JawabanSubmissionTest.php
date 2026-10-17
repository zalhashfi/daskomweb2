<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateSoalException;
use App\Exceptions\JawabanSubmissionLockedException;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTp;
use App\Services\Jawaban\SubmissionWriter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers T09: POST /api-v1/jawaban-tp goes through
 * App\Services\Jawaban\SubmissionWriter and every success is a NEW attempt.
 *
 * - first submit                  => 201 attempt_no=1
 * - second submit before lock     => 201 attempt_no=2, attempt 1 still present
 * - locked_at set                 => 409 SUBMISSION_LOCKED
 * - duplicate soal_id in request  => 422, zero rows for that attempt
 * - unique violation (lost race)  => retried once with the next attempt_no
 */
class JawabanSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api-v1/jawaban-tp';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    public function test_first_submit_creates_attempt_one(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $soal = SoalTp::factory()->for($modul)->create();

        $response = $this->postJson(self::ENDPOINT, [
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'jawaban' => 'Jawaban pertama.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('attempt_no', 1);

        $submissionId = $response->json('submission_id');
        $this->assertNotNull($submissionId);

        $this->assertDatabaseHas('jawaban_submissions', [
            'id' => $submissionId,
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
            'attempt_no' => 1,
            'locked_at' => null,
        ]);

        $jawaban = JawabanTp::query()->where('submission_id', $submissionId)->sole();
        $this->assertSame($soal->id, $jawaban->soal_id);
        $this->assertSame('Jawaban pertama.', $jawaban->jawaban);
        $this->assertSame($jawaban->id, JawabanSubmission::query()->find($submissionId)->jawaban_id);
    }

    public function test_second_submit_creates_attempt_two_and_keeps_attempt_one(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $soal = SoalTp::factory()->for($modul)->create();

        $first = $this->submit($praktikan, $modul, [['soal_id' => $soal->id, 'jawaban' => 'Versi pertama.']]);
        $first->assertCreated()->assertJsonPath('attempt_no', 1);

        $second = $this->submit($praktikan, $modul, [['soal_id' => $soal->id, 'jawaban' => 'Versi kedua.']]);
        $second->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('attempt_no', 2);

        $firstId = $first->json('submission_id');
        $secondId = $second->json('submission_id');
        $this->assertNotSame($firstId, $secondId);

        $this->assertSame(2, JawabanSubmission::query()->where('praktikan_id', $praktikan->id)->count());

        $attemptOne = JawabanSubmission::query()->find($firstId);
        $this->assertNotNull($attemptOne, 'Attempt 1 tidak boleh terhapus.');
        $this->assertSame(1, $attemptOne->attempt_no);
        $this->assertSame(
            'Versi pertama.',
            JawabanTp::query()->where('submission_id', $firstId)->sole()->jawaban,
            'Jawaban attempt 1 tidak boleh ditimpa.'
        );

        $attemptTwo = JawabanSubmission::query()->find($secondId);
        $this->assertSame(2, $attemptTwo->attempt_no);
        $this->assertSame(
            'Versi kedua.',
            JawabanTp::query()->where('submission_id', $secondId)->sole()->jawaban
        );
    }

    public function test_locked_submission_returns_409_and_writes_nothing(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $soal = SoalTp::factory()->for($modul)->create();

        $lockedAt = now()->startOfSecond();
        $existing = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
            'attempt_no' => 1,
            'locked_at' => $lockedAt,
            'locked_by_type' => 'asisten',
            'locked_by_id' => 1,
        ]);

        $response = $this->submit($praktikan, $modul, [['soal_id' => $soal->id, 'jawaban' => 'Terlambat.']]);

        $response->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'SUBMISSION_LOCKED')
            ->assertJsonPath('submission_id', $existing->id);

        $this->assertNotNull($response->json('locked_at'));
        $this->assertSame(1, JawabanSubmission::query()->where('praktikan_id', $praktikan->id)->count());
        $this->assertSame(0, JawabanTp::query()->where('praktikan_id', $praktikan->id)->count());
    }

    public function test_duplicate_soal_id_in_one_request_returns_422_with_zero_rows(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $soal = SoalTp::factory()->for($modul)->create();

        $response = $this->postJson(self::ENDPOINT, [
            ['praktikan_id' => $praktikan->id, 'modul_id' => $modul->id, 'soal_id' => $soal->id, 'jawaban' => 'Satu.'],
            ['praktikan_id' => $praktikan->id, 'modul_id' => $modul->id, 'soal_id' => $soal->id, 'jawaban' => 'Dua.'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('soal_id');

        $this->assertSame(0, JawabanSubmission::query()->where('praktikan_id', $praktikan->id)->count());
        $this->assertDatabaseCount('jawaban_tps', 0);
    }

    public function test_duplicate_soal_id_service_throws_validation_exception(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $soal = SoalTp::factory()->for($modul)->create();

        try {
            app(SubmissionWriter::class)->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
                ['soal_id' => $soal->id, 'jawaban' => 'Satu.'],
                ['soal_id' => $soal->id, 'jawaban' => 'Dua.'],
            ]);
            $this->fail('DuplicateSoalException harus dilempar.');
        } catch (DuplicateSoalException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $this->assertDatabaseCount('jawaban_submissions', 0);
        $this->assertDatabaseCount('jawaban_tps', 0);
    }

    public function test_unique_violation_is_retried_once_with_next_attempt_no(): void
    {
        // The retry path rolls back an inner transaction, which cannot be
        // observed inside RefreshDatabase's outer wrapping transaction. This
        // test therefore takes ownership of the rows it creates.
        $this->dropWrappingTransaction();

        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $soalA = SoalTp::factory()->for($modul)->create();
        $soalB = SoalTp::factory()->for($modul)->create();

        app(SubmissionWriter::class)->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
            ['soal_id' => $soalA->id, 'jawaban' => 'Pertama.'],
        ]);

        $writer = $this->fakeWriter();
        $writer->losingWrites = 1;

        // Tidak ada exception yang keluar: service menangkap unique violation
        // sekali lalu mengulang, bukan mengembalikan 500.
        $submission = $writer->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
            ['soal_id' => $soalB->id, 'jawaban' => 'Kedua.'],
        ]);

        $this->assertSame(2, $writer->writes, 'Insert harus dicoba tepat dua kali.');
        $this->assertSame(3, $submission->attempt_no, 'Retry harus memakai attempt_no berikutnya, bukan 500.');

        $attemptNos = JawabanSubmission::query()
            ->where('praktikan_id', $praktikan->id)
            ->where('tipe', JawabanSubmission::TIPE_TP)
            ->orderBy('attempt_no')
            ->pluck('attempt_no')
            ->all();

        $this->assertContains(1, $attemptNos, 'Attempt 1 tetap ada.');
        $this->assertContains(3, $attemptNos, 'Attempt hasil retry tersimpan.');
        $this->assertSame(3, max($attemptNos), 'Retry menghasilkan attempt_no tertinggi.');
        $this->assertSame(
            'Kedua.',
            JawabanTp::query()->where('submission_id', $submission->id)->sole()->jawaban
        );
    }

    public function test_second_unique_violation_is_not_swallowed(): void
    {
        $this->dropWrappingTransaction();

        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $soal = SoalTp::factory()->for($modul)->create();

        $writer = $this->fakeWriter();
        $writer->losingWrites = 2;

        try {
            $writer->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
                ['soal_id' => $soal->id, 'jawaban' => 'Gagal.'],
            ]);
            $this->fail('QueryException kedua harus dibiarkan naik.');
        } catch (QueryException $exception) {
            $this->assertSame(1062, (int) $exception->getCode());
        }

        $this->assertSame(2, $writer->writes, 'Hanya boleh ada dua percobaan.');
        $this->assertSame(0, JawabanTp::query()->where('praktikan_id', $praktikan->id)->count(), 'Tidak ada jawaban yang tersimpan.');
    }

    public function test_lock_check_runs_before_attempt_number_is_computed(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $lockedAt = now()->startOfSecond();

        JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
            'attempt_no' => 1,
        ]);
        $locked = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
            'attempt_no' => 2,
            'locked_at' => $lockedAt,
        ]);

        try {
            app(SubmissionWriter::class)->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
                ['soal_id' => 1, 'jawaban' => 'Terlambat.'],
            ]);
            $this->fail('JawabanSubmissionLockedException harus dilempar.');
        } catch (JawabanSubmissionLockedException $exception) {
            $this->assertSame($locked->id, $exception->submissionId);
            $this->assertTrue($exception->lockedAt->equalTo($lockedAt));
        }

        $this->assertSame(2, JawabanSubmission::query()->where('praktikan_id', $praktikan->id)->count());
    }

    public function test_writer_uses_for_update_locks(): void
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $soal = SoalTp::factory()->for($modul)->create();

        DB::enableQueryLog();

        app(SubmissionWriter::class)->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
            ['soal_id' => $soal->id, 'jawaban' => 'Pertama.'],
        ]);

        $withoutSlot = collect(DB::getQueryLog())->pluck('query');

        $this->assertTrue(
            $withoutSlot->contains(fn (string $sql): bool => str_contains($sql, 'jawaban_submissions') && str_contains($sql, 'for update')),
            'Submit pertama harus mengunci baris submission (FOR UPDATE).'
        );
        $this->assertTrue(
            $withoutSlot->contains(fn (string $sql): bool => str_contains($sql, 'praktikans') && str_contains($sql, 'for update')),
            'Bila belum ada submission, baris praktikans harus dikunci.'
        );

        DB::flushQueryLog();

        app(SubmissionWriter::class)->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
            ['soal_id' => $soal->id, 'jawaban' => 'Kedua.'],
        ]);

        $withSlot = collect(DB::getQueryLog())->pluck('query');

        $this->assertTrue(
            $withSlot->contains(fn (string $sql): bool => str_contains($sql, 'jawaban_submissions') && str_contains($sql, 'for update')),
            'Submit berikutnya harus mengunci baris submission terakhir.'
        );
        $this->assertFalse(
            $withSlot->contains(fn (string $sql): bool => str_contains($sql, 'praktikans') && str_contains($sql, 'for update')),
            'Bila submission sudah ada, praktikans tidak perlu dikunci.'
        );

        DB::disableQueryLog();
    }

    /**
     * A SubmissionWriter whose first `$this->losingWrites` attempts emulate a
     * concurrent request that already committed the same attempt_no: a
     * competitor row is inserted for that attempt_no and the attempt then
     * fails with the exact 1062 QueryException MySQL raises for the unique
     * (praktikan_id, modul_id, tipe, attempt_no) index.
     *
     * Everything else — the transaction, SELECT ... FOR UPDATE, the lock check
     * and attempt_no = max+1 — is the production code. The case keeps no
     * constructor on purpose: its attributes are set after instantiation.
     */
    /**
     * RefreshDatabase wraps each test in a transaction. A rolled-back inner
     * transaction (the retry path) is invisible inside it, so tests that
     * exercise the retry commit the wrapper first and clean up by hand.
     */
    private function dropWrappingTransaction(): void
    {
        if (DB::connection()->transactionLevel() === 0) {
            return;
        }

        DB::commit();

        $connection = DB::connection();
        $database = $connection->getDatabaseName();

        $this->beforeApplicationDestroyed(function () use ($connection, $database): void {
            $statement = 'SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0';
            $connection->unprepared($statement);

            foreach (['jawaban_tps', 'jawaban_submissions', 'soal_tps', 'praktikans', 'moduls'] as $table) {
                if ($connection->getSchemaBuilder()->hasTable($table)) {
                    $connection->statement("TRUNCATE TABLE `{$database}`.`{$table}`");
                }
            }

            $connection->unprepared('SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS');
        });
    }

    private function fakeWriter(): SubmissionWriter
    {
        return new class extends SubmissionWriter
        {
            public int $losingWrites = 0;

            public int $writes = 0;

            protected function writeAttempt(string $tipe, int $praktikanId, int $modulId, array $answers): JawabanSubmission
            {
                if ($this->writes++ < $this->losingWrites) {
                    // Simulate a concurrent request that COMMITTED its attempt
                    // before ours reaches the insert. The row therefore lives
                    // outside the enclosing transaction, so rollback of our
                    // attempt does not remove the competitor's slot.
                    $competitor = DB::connection();

                    if ($competitor->transactionLevel() > 0) {
                        $competitor->commit();
                    }

                    $attemptNo = (int) JawabanSubmission::query()
                        ->where('praktikan_id', $praktikanId)
                        ->where('modul_id', $modulId)
                        ->where('tipe', $tipe)
                        ->max('attempt_no') + 1;

                    JawabanSubmission::create([
                        'praktikan_id' => $praktikanId,
                        'modul_id' => $modulId,
                        'tipe' => $tipe,
                        'attempt_no' => $attemptNo,
                    ]);

                    throw new QueryException(
                        'mysql',
                        'insert into `jawaban_submissions` (`praktikan_id`, `modul_id`, `tipe`, `attempt_no`) values (?, ?, ?, ?)',
                        [$praktikanId, $modulId, $tipe, $attemptNo],
                        new \PDOException(
                            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key 'jawaban_submissions_praktikan_id_modul_id_tipe_attempt_no_unique'",
                            1062
                        )
                    );
                }

                return parent::writeAttempt($tipe, $praktikanId, $modulId, $answers);
            }
        };
    }

    /**
     * @param  list<array{soal_id:int, jawaban:string}>  $answers
     */
    private function submit(Praktikan $praktikan, Modul $modul, array $answers): \Illuminate\Testing\TestResponse
    {
        $payload = array_map(fn (array $answer): array => [
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $answer['soal_id'],
            'jawaban' => $answer['jawaban'],
        ], $answers);

        return $this->postJson(self::ENDPOINT, $payload);
    }

    private function actingAsPraktikan(): Praktikan
    {
        $praktikan = Praktikan::factory()->create();
        $this->actingAs($praktikan, 'praktikan');

        return $praktikan;
    }
}
