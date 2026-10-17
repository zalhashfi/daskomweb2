<?php

namespace Tests\Feature;

use App\Jobs\EvaluateAnswer;
use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use App\Services\Jawaban\SubmissionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * T27 — EvaluateAnswer is dispatched after commit, one job per `queued`
 * ai_evaluations row.
 *
 * - 5 text answers + active set => 5 jobs, 5 rows queued
 * - transaction rolled back       => 0 jobs, 0 rows
 * - attachment answer             => manual_required row, 0 job for it
 * - no active set                 => 0 jobs, 0 rows
 * - dispatch runs AFTER commit    => the worker sees the committed row
 *
 * Rows are still created ONLY by the chokepoint (T23a); this file asserts
 * what T27 adds on top of it.
 */
class SubmissionDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.openai_compatible.base_url' => 'http://127.0.0.1:20128',
            'ai.openai_compatible.api_key' => 'sk-test-secret-dispatch',
            'ai.openai_compatible.chat_model' => 'gemini/gemini-flash',
        ]);
    }

    public function test_five_answers_with_active_set_push_five_jobs_and_five_queued_rows(): void
    {
        Queue::fake();

        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $v1 = $this->versionSet($modul, 1, now()->utc());

        $this->allowPayloadConfig('openai_compatible');

        $answers = [];
        for ($i = 0; $i < 5; $i++) {
            $answers[] = [
                'soal_id' => SoalTp::factory()->for($modul)->create()->id,
                'jawaban' => 'Jawaban nomor '.$i.'.',
            ];
        }

        $submission = $this->writer()->write(
            JawabanSubmission::TIPE_TP,
            $praktikan->id,
            $modul->id,
            $answers
        );

        Queue::assertPushed(EvaluateAnswer::class, 5);

        $rows = AiEvaluation::query()->where('version_set_id', $v1->id)->get();
        $this->assertCount(5, $rows, 'Submit 5 jawaban harus membuat 5 baris ai_evaluations.');
        $this->assertSame(
            5,
            $rows->where('status', AiEvaluation::STATUS_QUEUED)->count(),
            'Kelima baris harus queued.'
        );

        // One job per row: the pushed evaluations are exactly the row ids.
        $pushedIds = collect(Queue::pushed(EvaluateAnswer::class))
            ->map(fn (EvaluateAnswer $job): int => (int) $job->evaluation->getKey())
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $rows->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            $pushedIds
        );

        // Jobs carry ai_evaluations rows, so the coverage check compares the
        // evaluation ids of this submission's answers, not the answer ids.
        $evaluationIds = AiEvaluation::query()
            ->where('jawaban_type', JawabanSubmission::TIPE_TP)
            ->whereIn('jawaban_id', JawabanTp::query()
                ->where('submission_id', $submission->id)
                ->pluck('id')
                ->all())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertSame([], array_diff($evaluationIds, $pushedIds), 'Setiap evaluasi harus punya satu job.');
    }

    public function test_rolled_back_transaction_pushes_zero_jobs_and_keeps_zero_rows(): void
    {
        Queue::fake();

        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $this->versionSet($modul, 1, now()->utc());
        $this->allowPayloadConfig('openai_compatible');

        $answers = [];
        for ($i = 0; $i < 5; $i++) {
            $answers[] = [
                'soal_id' => SoalTp::factory()->for($modul)->create()->id,
                'jawaban' => 'Jawaban '.$i.'.',
            ];
        }

        try {
            DB::transaction(function () use ($praktikan, $modul, $answers): void {
                $this->writer()->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, $answers);

                // Inside the transaction the job is only scheduled, not pushed.
                Queue::assertNothingPushed();

                throw new RuntimeException('Rollback after the chokepoint.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback after the chokepoint.', $exception->getMessage());
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, AiEvaluation::query()->count(), 'Rollback → 0 baris ai_evaluations.');
        $this->assertSame(0, JawabanTp::query()->count(), 'Rollback → 0 baris jawaban.');
        $this->assertSame(0, JawabanSubmission::query()->count(), 'Rollback → 0 submission.');
    }

    public function test_attachment_answer_is_never_dispatched(): void
    {
        Queue::fake();

        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $this->versionSet($modul, 1, now()->utc());
        $this->allowPayloadConfig('openai_compatible');

        $lampiran = SoalTp::factory()->for($modul)->create(['enable_file_upload' => true]);
        $teks = SoalTp::factory()->for($modul)->create(['enable_file_upload' => false]);

        $submission = $this->writer()->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
            ['soal_id' => $lampiran->id, 'jawaban' => '-'],
            ['soal_id' => $teks->id, 'jawaban' => 'Jawaban teks.'],
        ]);

        $manualId = (int) JawabanTp::query()
            ->where('submission_id', $submission->id)
            ->where('soal_id', $lampiran->id)
            ->value('id');
        $textId = (int) JawabanTp::query()
            ->where('submission_id', $submission->id)
            ->where('soal_id', $teks->id)
            ->value('id');

        // The chokepoint still creates the manual_required row…
        $this->assertSame(
            AiEvaluation::STATUS_MANUAL_REQUIRED,
            AiEvaluation::query()->where('jawaban_id', $manualId)->sole()->status
        );

        // …but T27 dispatches only the text answer.
        Queue::assertPushed(EvaluateAnswer::class, 1);
        $pushedIds = collect(Queue::pushed(EvaluateAnswer::class))
            ->map(fn (EvaluateAnswer $job): int => (int) $job->evaluation->getKey())
            ->all();

        $textEvaluationId = (int) AiEvaluation::query()->where('jawaban_id', $textId)->value('id');

        $this->assertSame([$textEvaluationId], $pushedIds, 'Lampiran (manual_required) tidak boleh di-dispatch.');
    }

    public function test_submit_without_active_set_pushes_zero_jobs_and_creates_zero_rows(): void
    {
        Queue::fake();

        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $this->versionSet($modul, 1); // drafted, never activated
        $this->allowPayloadConfig('openai_compatible');

        $answers = [];
        for ($i = 0; $i < 5; $i++) {
            $answers[] = [
                'soal_id' => SoalTp::factory()->for($modul)->create()->id,
                'jawaban' => 'Jawaban '.$i.'.',
            ];
        }

        $submission = $this->writer()->write(
            JawabanSubmission::TIPE_TP,
            $praktikan->id,
            $modul->id,
            $answers
        );

        $this->assertNull($submission->version_set_id);
        $this->assertSame(5, JawabanTp::query()->where('submission_id', $submission->id)->count());
        $this->assertSame(0, AiEvaluation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_jobs_are_dispatched_only_after_the_commit(): void
    {
        Queue::fake();

        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $this->versionSet($modul, 1, now()->utc());
        $this->allowPayloadConfig('openai_compatible');

        $soal = SoalTp::factory()->for($modul)->create();

        $levelBefore = DB::transactionLevel();

        $this->writer()->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
            ['soal_id' => $soal->id, 'jawaban' => 'Jawaban.'],
        ]);

        Queue::assertPushed(EvaluateAnswer::class, 1);
        $this->assertSame(
            $levelBefore,
            DB::transactionLevel(),
            'Callback after-commit harus berjalan tepat setelah ROOT transaksi submit, bukan di dalamnya.'
        );

        $row = AiEvaluation::query()->sole();
        $this->assertSame(AiEvaluation::STATUS_QUEUED, $row->status);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function writer(): SubmissionWriter
    {
        return app(SubmissionWriter::class);
    }

    private function versionSet(Modul $modul, int $versionNo, mixed $activatedAt = null): SoalVersionSet
    {
        $set = SoalVersionSet::query()->create([
            'modul_id' => $modul->id,
            'version_no' => $versionNo,
            'status' => $activatedAt === null ? SoalVersionSet::STATUS_DRAFT : SoalVersionSet::STATUS_APPROVED,
            'bobot_total' => 100,
            'activated_at' => $activatedAt,
        ]);

        foreach (SoalTp::query()->where('modul_id', $modul->id)->get() as $soal) {
            SoalVersionItem::query()->create([
                'version_set_id' => $set->id,
                'soal_type' => SoalVersionItem::SOAL_TP,
                'soal_id' => $soal->id,
                'bobot' => 100,
                'max_score' => 100,
                'teks_snapshot' => $soal->soal,
                'jawaban_standar_status' => SoalVersionItem::STATUS_PENDING,
            ]);
        }

        return $set;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function allowPayloadConfig(string $driver, array $overrides = []): AiConfig
    {
        DB::table('ai_configs')->update(['is_active' => false]);

        return AiConfig::query()->create(array_merge([
            'driver' => $driver,
            'retrieval_top_k' => 0,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'allow_external_payload' => true,
            'ai_enabled' => true,
            'budget_tokens_per_modul' => 100000,
            'provider_policy_verified_at' => now(),
            'is_active' => true,
        ], $overrides));
    }
}
