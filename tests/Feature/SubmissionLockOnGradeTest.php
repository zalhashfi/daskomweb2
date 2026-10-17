<?php

namespace Tests\Feature;

use App\Models\Asisten;
use App\Models\AuditLog;
use App\Models\JawabanSubmission;
use App\Models\Kelas;
use App\Models\Modul;
use App\Models\Nilai;
use App\Models\Praktikan;
use App\Models\SoalMandiri;
use App\Models\SoalTp;
use App\Services\Jawaban\SubmissionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers the NilaiObserver: saving (creating OR updating) a Nilai for a
 * (praktikan, modul) pair locks the latest 'tp' submission with
 * locked_by_type = 'system' and writes a 'submission.locked' audit entry.
 * 'tm' submissions are never touched.
 */
class SubmissionLockOnGradeTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api-v1/jawaban-tp';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    public function test_saving_nilai_locks_latest_tp_submission(): void
    {
        [$praktikan, $modul] = $this->makeContext();
        $submission = $this->createSubmission($praktikan, $modul, JawabanSubmission::TIPE_TP, 1);

        $latest = $this->createSubmission($praktikan, $modul, JawabanSubmission::TIPE_TP, 3);

        $this->assertNull($submission->fresh()->locked_at);

        $this->createNilai($praktikan, $modul);

        $locked = $latest->fresh();
        $this->assertNotNull($locked->locked_at);
        $this->assertSame('system', $locked->locked_by_type);
        $this->assertNull($locked->locked_by_id);
    }

    public function test_creating_nilai_locks_only_the_latest_attempt(): void
    {
        [$praktikan, $modul] = $this->makeContext();
        $first = $this->createSubmission($praktikan, $modul, JawabanSubmission::TIPE_TP, 1);
        $second = $this->createSubmission($praktikan, $modul, JawabanSubmission::TIPE_TP, 2);

        $this->createNilai($praktikan, $modul);

        $this->assertNotNull($second->fresh()->locked_at);
        $this->assertNull($first->fresh()->locked_at, 'Hanya submission tp terakhir yang dikunci.');
    }

    public function test_updating_nilai_also_locks_latest_tp_submission(): void
    {
        [$praktikan, $modul] = $this->makeContext();
        $nilai = $this->createNilai($praktikan, $modul);

        $submission = $this->createSubmission($praktikan, $modul, JawabanSubmission::TIPE_TP, 1);
        $this->assertNull($submission->fresh()->locked_at);

        $nilai->update(['tp' => 90]);

        $locked = $submission->fresh();
        $this->assertNotNull($locked->locked_at);
        $this->assertSame('system', $locked->locked_by_type);
        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'submission.locked')
                ->where('subject_type', 'submission')
                ->where('subject_id', $submission->id)
                ->exists(),
            'Update Nilai harus mengunci dan menulis audit.'
        );
    }

    public function test_tm_submission_is_never_locked(): void
    {
        [$praktikan, $modul] = $this->makeContext();
        $tm = $this->createSubmission($praktikan, $modul, JawabanSubmission::TIPE_TM, 1);
        $tp = $this->createSubmission($praktikan, $modul, JawabanSubmission::TIPE_TP, 1);

        $nilai = $this->createNilai($praktikan, $modul);
        $nilai->update(['tp' => 75]);

        $this->assertNull($tm->fresh()->locked_at);
        $this->assertNull($tm->fresh()->locked_by_type);
        $this->assertNotNull($tp->fresh()->locked_at);
    }

    public function test_lock_writes_system_audit_entry(): void
    {
        [$praktikan, $modul] = $this->makeContext();
        $submission = $this->createSubmission($praktikan, $modul, JawabanSubmission::TIPE_TP, 1);

        $this->createNilai($praktikan, $modul);

        $log = AuditLog::query()
            ->where('subject_type', 'submission')
            ->where('subject_id', $submission->id)
            ->sole();

        $this->assertSame('submission.locked', $log->action);
        $this->assertSame('system', $log->actor_type);
        $this->assertNull($log->actor_id);
        $this->assertSame($modul->id, $log->metadata['modul_id']);
        $this->assertSame($praktikan->id, $log->metadata['praktikan_id']);
    }

    public function test_next_tp_submit_after_grading_returns_409(): void
    {
        $praktikan = Praktikan::factory()->create();
        $this->actingAs($praktikan, 'praktikan');
        $modul = Modul::factory()->create();
        $soal = SoalTp::factory()->for($modul)->create();

        $first = $this->postJson(self::ENDPOINT, [
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'jawaban' => 'Jawaban sebelum dinilai.',
        ]);
        $first->assertCreated();
        $submissionId = $first->json('submission_id');

        $this->createNilai($praktikan, $modul);

        $this->assertNotNull(JawabanSubmission::query()->find($submissionId)->locked_at);

        $second = $this->postJson(self::ENDPOINT, [
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'jawaban' => 'Jawaban setelah dinilai.',
        ]);

        $second->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'SUBMISSION_LOCKED')
            ->assertJsonPath('submission_id', $submissionId);

        $this->assertSame(1, JawabanSubmission::query()
            ->where('praktikan_id', $praktikan->id)
            ->where('tipe', JawabanSubmission::TIPE_TP)
            ->count());
    }

    public function test_tm_submit_still_allowed_after_grading(): void
    {
        [$praktikan, $modul] = $this->makeContext();
        $this->createSubmission($praktikan, $modul, JawabanSubmission::TIPE_TP, 1);

        $this->createNilai($praktikan, $modul);

        $soal = SoalMandiri::factory()->for($modul)->create();

        $submission = app(SubmissionWriter::class)->write(JawabanSubmission::TIPE_TM, $praktikan->id, $modul->id, [
            ['soal_id' => $soal->id, 'jawaban' => 'Masih boleh dikumpulkan.'],
        ]);

        $this->assertSame(1, $submission->attempt_no);
        $this->assertNull($submission->locked_at);
    }

    public function test_grading_without_tp_submission_does_not_fail(): void
    {
        [$praktikan, $modul] = $this->makeContext();

        $nilai = $this->createNilai($praktikan, $modul);

        $this->assertNotNull($nilai->id);
        $this->assertSame(0, AuditLog::query()->where('action', 'submission.locked')->count());
    }

    /**
     * @return array{0: Praktikan, 1: Modul}
     */
    private function makeContext(): array
    {
        return [Praktikan::factory()->create(), Modul::factory()->create()];
    }

    private function createSubmission(Praktikan $praktikan, Modul $modul, string $tipe, int $attemptNo): JawabanSubmission
    {
        return JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => $tipe,
            'attempt_no' => $attemptNo,
        ]);
    }

    private function createAsisten(): Asisten
    {
        $role = Role::firstOrCreate([
            'name' => 'TEST-SUBMISSION-LOCK-ROLE',
            'guard_name' => 'asisten',
        ]);

        return Asisten::factory()->create(['role_id' => $role->id]);
    }

    private function createNilai(Praktikan $praktikan, Modul $modul): Nilai
    {
        return Nilai::create([
            'tp' => 80,
            'ta' => 80,
            'd1' => 80,
            'd2' => 80,
            'd3' => 80,
            'd4' => 80,
            'l1' => 80,
            'l2' => 80,
            'avg' => 80,
            'modul_id' => $modul->id,
            'asisten_id' => $this->createAsisten()->id,
            'kelas_id' => $praktikan->kelas_id ?? Kelas::factory()->create()->id,
            'praktikan_id' => $praktikan->id,
        ]);
    }
}
