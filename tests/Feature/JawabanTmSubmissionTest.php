<?php

namespace Tests\Feature;

use App\Models\JawabanMandiri;
use App\Models\JawabanSubmission;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalMandiri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api-v1/jawaban-tm now goes through
 * App\Services\Jawaban\SubmissionWriter (TIPE_TM) exactly like the TP
 * endpoint: every success creates a NEW attempt and nothing is ever
 * deleted before the write succeeds.
 *
 * - first submit                  => 201 attempt_no=1
 * - second submit before lock     => 201 attempt_no=2, attempt 1 untouched
 * - locked_at set                 => 409 SUBMISSION_LOCKED, rows unchanged
 * - duplicate soal_id in request  => 422, zero rows for that attempt,
 *                                    previous answers still there
 */
class JawabanTmSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api-v1/jawaban-tm';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    public function test_first_submit_creates_attempt_one(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $soal = SoalMandiri::factory()->for($modul)->create();

        $response = $this->submit($praktikan, $modul, [
            ['soal_id' => $soal->id, 'jawaban' => 'Jawaban pertama.'],
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
            'tipe' => JawabanSubmission::TIPE_TM,
            'attempt_no' => 1,
            'locked_at' => null,
        ]);

        $jawaban = JawabanMandiri::query()->where('submission_id', $submissionId)->sole();
        $this->assertSame($soal->id, $jawaban->soal_id);
        $this->assertSame('Jawaban pertama.', $jawaban->jawaban);
        $this->assertSame($jawaban->id, JawabanSubmission::query()->find($submissionId)->jawaban_id);
    }

    public function test_second_submit_creates_attempt_two_and_keeps_attempt_one(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $soalA = SoalMandiri::factory()->for($modul)->create();
        $soalB = SoalMandiri::factory()->for($modul)->create();

        $first = $this->submit($praktikan, $modul, [
            ['soal_id' => $soalA->id, 'jawaban' => 'Versi pertama A.'],
            ['soal_id' => $soalB->id, 'jawaban' => 'Versi pertama B.'],
        ]);
        $first->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('attempt_no', 1);

        $second = $this->submit($praktikan, $modul, [
            ['soal_id' => $soalA->id, 'jawaban' => 'Versi kedua A.'],
            ['soal_id' => $soalB->id, 'jawaban' => 'Versi kedua B.'],
        ]);
        $second->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('attempt_no', 2);

        $firstId = $first->json('submission_id');
        $secondId = $second->json('submission_id');
        $this->assertNotSame($firstId, $secondId);

        $this->assertSame(2, JawabanSubmission::query()
            ->where('praktikan_id', $praktikan->id)
            ->where('tipe', JawabanSubmission::TIPE_TM)
            ->count());

        $attemptOne = JawabanSubmission::query()->find($firstId);
        $this->assertNotNull($attemptOne, 'Attempt 1 tidak boleh terhapus.');
        $this->assertSame(1, $attemptOne->attempt_no);

        $answersOne = JawabanMandiri::query()
            ->where('submission_id', $firstId)
            ->orderBy('soal_id')
            ->pluck('jawaban', 'soal_id')
            ->all();
        $this->assertSame([
            $soalA->id => 'Versi pertama A.',
            $soalB->id => 'Versi pertama B.',
        ], $answersOne, 'Jawaban attempt 1 tidak boleh berubah.');

        $attemptTwo = JawabanSubmission::query()->find($secondId);
        $this->assertSame(2, $attemptTwo->attempt_no);

        $answersTwo = JawabanMandiri::query()
            ->where('submission_id', $secondId)
            ->orderBy('soal_id')
            ->pluck('jawaban', 'soal_id')
            ->all();
        $this->assertSame([
            $soalA->id => 'Versi kedua A.',
            $soalB->id => 'Versi kedua B.',
        ], $answersTwo);
    }

    public function test_locked_submission_returns_409_and_writes_nothing(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $soal = SoalMandiri::factory()->for($modul)->create();

        $lockedAt = now()->startOfSecond();
        $existing = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TM,
            'attempt_no' => 1,
            'locked_at' => $lockedAt,
            'locked_by_type' => 'asisten',
            'locked_by_id' => 1,
        ]);

        $oldAnswer = JawabanMandiri::create([
            'submission_id' => $existing->id,
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'jawaban' => 'Jawaban lama.',
        ]);

        $response = $this->submit($praktikan, $modul, [
            ['soal_id' => $soal->id, 'jawaban' => 'Terlambat.'],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'SUBMISSION_LOCKED')
            ->assertJsonPath('submission_id', $existing->id);

        $this->assertNotNull($response->json('locked_at'));

        $this->assertSame(1, JawabanSubmission::query()
            ->where('praktikan_id', $praktikan->id)
            ->where('tipe', JawabanSubmission::TIPE_TM)
            ->count());
        $this->assertSame(1, JawabanMandiri::query()->where('praktikan_id', $praktikan->id)->count());
        $this->assertSame('Jawaban lama.', $oldAnswer->fresh()->jawaban);
    }

    public function test_duplicate_soal_id_in_one_request_returns_422_with_zero_rows(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $soal = SoalMandiri::factory()->for($modul)->create();

        $response = $this->postJson(self::ENDPOINT, [
            ['praktikan_id' => $praktikan->id, 'modul_id' => $modul->id, 'soal_id' => $soal->id, 'jawaban' => 'Satu.'],
            ['praktikan_id' => $praktikan->id, 'modul_id' => $modul->id, 'soal_id' => $soal->id, 'jawaban' => 'Dua.'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('soal_id');

        $this->assertSame(0, JawabanSubmission::query()->where('praktikan_id', $praktikan->id)->count());
        $this->assertSame(0, JawabanMandiri::query()->where('praktikan_id', $praktikan->id)->count());
    }

    public function test_duplicate_soal_id_does_not_delete_previous_answers(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $soalA = SoalMandiri::factory()->for($modul)->create();
        $soalB = SoalMandiri::factory()->for($modul)->create();

        $first = $this->submit($praktikan, $modul, [
            ['soal_id' => $soalA->id, 'jawaban' => 'Tersimpan A.'],
            ['soal_id' => $soalB->id, 'jawaban' => 'Tersimpan B.'],
        ]);
        $first->assertCreated();
        $firstId = $first->json('submission_id');

        $response = $this->postJson(self::ENDPOINT, [
            ['praktikan_id' => $praktikan->id, 'modul_id' => $modul->id, 'soal_id' => $soalA->id, 'jawaban' => 'Gagal A.'],
            ['praktikan_id' => $praktikan->id, 'modul_id' => $modul->id, 'soal_id' => $soalA->id, 'jawaban' => 'Gagal B.'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('soal_id');

        $this->assertSame(1, JawabanSubmission::query()
            ->where('praktikan_id', $praktikan->id)
            ->where('tipe', JawabanSubmission::TIPE_TM)
            ->count(), 'Submit gagal tidak boleh menambah attempt.');
        $this->assertSame(2, JawabanMandiri::query()->where('praktikan_id', $praktikan->id)->count());

        $answers = JawabanMandiri::query()
            ->where('submission_id', $firstId)
            ->orderBy('soal_id')
            ->pluck('jawaban', 'soal_id')
            ->all();
        $this->assertSame([
            $soalA->id => 'Tersimpan A.',
            $soalB->id => 'Tersimpan B.',
        ], $answers, 'Jawaban lama tidak boleh hilang saat submit gagal.');
    }

    public function test_rejected_request_with_mixed_praktikan_keeps_previous_answers(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $soal = SoalMandiri::factory()->for($modul)->create();

        $first = $this->submit($praktikan, $modul, [
            ['soal_id' => $soal->id, 'jawaban' => 'Tetap ada.'],
        ]);
        $first->assertCreated();

        $other = Praktikan::factory()->create();

        $response = $this->postJson(self::ENDPOINT, [
            ['praktikan_id' => $praktikan->id, 'modul_id' => $modul->id, 'soal_id' => $soal->id, 'jawaban' => 'X.'],
            ['praktikan_id' => $other->id, 'modul_id' => $modul->id, 'soal_id' => $soal->id, 'jawaban' => 'Y.'],
        ]);

        $response->assertStatus(422);

        $this->assertSame(1, JawabanSubmission::query()
            ->where('praktikan_id', $praktikan->id)
            ->where('tipe', JawabanSubmission::TIPE_TM)
            ->count());
        $this->assertSame('Tetap ada.', JawabanMandiri::query()
            ->where('praktikan_id', $praktikan->id)
            ->sole()
            ->jawaban);
        $this->assertSame(0, JawabanMandiri::query()->where('praktikan_id', $other->id)->count());
    }

    /**
     * @param  list<array{soal_id:int, jawaban:string}>  $answers
     */
    private function submit(Praktikan $praktikan, Modul $modul, array $answers): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::ENDPOINT, array_map(
            fn (array $answer): array => [
                'praktikan_id' => $praktikan->id,
                'modul_id' => $modul->id,
                'soal_id' => $answer['soal_id'],
                'jawaban' => $answer['jawaban'],
            ],
            $answers,
        ));
    }

    private function actingAsPraktikan(): Praktikan
    {
        $praktikan = Praktikan::factory()->create();
        $this->actingAs($praktikan, 'praktikan');

        return $praktikan;
    }
}
