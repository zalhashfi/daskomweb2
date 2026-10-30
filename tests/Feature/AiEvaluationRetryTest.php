<?php

namespace Tests\Feature;

use App\Jobs\EvaluateAnswer;
use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\Asisten;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use App\Services\Jawaban\SubmissionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * POST /api-v1/ai-evaluations/{submission}/retry
 *
 * - 3 done + 2 failed → 202 retried=2 skipped_done=3, exactly 2 EvaluateAnswer
 *   jobs, and NO new ai_evaluations rows
 * - failure detail is cleared on retried rows
 * - a second retry finds nothing failed and pushes nothing
 * - submission locked → 409, nothing changed, nothing pushed
 * - unknown submission → 404
 * - assistant without `nilai-praktikan` → 403
 *
 * The queue is faked only after the submission is written, so the submit-time
 * dispatch is recorded too and every assertion below covers ALL pushes.
 */
class AiEvaluationRetryTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api-v1/ai-evaluations/%d/retry';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutCsrf();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_retries_only_failed_rows_without_creating_new_rows(): void
    {
        $this->actingAsAssistantWithNilaiPermission();

        $this->allowPayloadConfig();
        $submission = $this->makeSubmissionWithAnswers(5);

        $rows = $this->evaluationsOf($submission);
        $this->assertCount(5, $rows, 'Lima baris canonical sebelum retry.');

        $this->setStatus($rows->slice(0, 3)->all(), AiEvaluation::STATUS_DONE);
        $this->setStatus($rows->slice(3)->values()->all(), AiEvaluation::STATUS_FAILED);

        $before = AiEvaluation::query()->count();

        Queue::fake();

        $response = $this->postJson(sprintf(self::ENDPOINT, $submission->id));

        $response->assertStatus(202);
        $response->assertExactJson(['retried' => 2, 'skipped_done' => 3]);

        Queue::assertPushed(EvaluateAnswer::class, 2);

        $this->assertSame($before, AiEvaluation::query()->count(), 'Retry tidak boleh menambah baris ai_evaluations.');

        $after = $this->evaluationsOf($submission);

        $this->assertCount(5, $after);
        $this->assertSame(3, $after->where('status', AiEvaluation::STATUS_DONE)->count(), 'done tidak boleh disentuh.');
        $this->assertSame(2, $after->where('status', AiEvaluation::STATUS_QUEUED)->count());

        $pushedIds = collect(Queue::pushed(EvaluateAnswer::class))
            ->map(fn (EvaluateAnswer $job): int => (int) $job->evaluation->getKey())
            ->sort()
            ->values()
            ->all();

        $failedIds = $rows->slice(3)->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        $this->assertSame($failedIds, $pushedIds, 'Satu job per baris failed yang di-retry.');
    }

    public function test_retry_clears_the_previous_failure_detail(): void
    {
        $this->actingAsAssistantWithNilaiPermission();

        $this->allowPayloadConfig();
        $submission = $this->makeSubmissionWithAnswers(1);

        $row = $this->evaluationsOf($submission)->first();
        $row->forceFill([
            'status' => AiEvaluation::STATUS_FAILED,
            'error_code' => 'EXTERNAL_UNAVAILABLE',
            'error_message' => 'provider down',
            'finished_at' => now(),
        ])->save();

        Queue::fake();

        $this->postJson(sprintf(self::ENDPOINT, $submission->id))
            ->assertStatus(202)
            ->assertExactJson(['retried' => 1, 'skipped_done' => 0]);

        $fresh = $row->fresh();

        $this->assertSame(AiEvaluation::STATUS_QUEUED, $fresh->status);
        $this->assertNull($fresh->error_code);
        $this->assertNull($fresh->error_message);
        $this->assertNull($fresh->finished_at);
        $this->assertSame(1, AiEvaluation::query()->count());
    }

    public function test_second_retry_finds_nothing_failed_and_dispatches_nothing(): void
    {
        $this->actingAsAssistantWithNilaiPermission();

        $this->allowPayloadConfig();
        $submission = $this->makeSubmissionWithAnswers(2);

        $rows = $this->evaluationsOf($submission);
        $this->setStatus([$rows[0]], AiEvaluation::STATUS_DONE);
        $this->setStatus([$rows[1]], AiEvaluation::STATUS_FAILED);

        Queue::fake();

        $this->postJson(sprintf(self::ENDPOINT, $submission->id))
            ->assertStatus(202)
            ->assertExactJson(['retried' => 1, 'skipped_done' => 1]);

        Queue::assertPushed(EvaluateAnswer::class, 1);

        $this->postJson(sprintf(self::ENDPOINT, $submission->id))
            ->assertStatus(202)
            ->assertExactJson(['retried' => 0, 'skipped_done' => 1]);

        Queue::assertPushed(EvaluateAnswer::class, 1);
        $this->assertSame(2, AiEvaluation::query()->count());
    }

    public function test_locked_submission_returns_409_and_changes_nothing(): void
    {
        $this->actingAsAssistantWithNilaiPermission();

        $this->allowPayloadConfig();
        $submission = $this->makeSubmissionWithAnswers(2);

        $this->setStatus($this->evaluationsOf($submission)->all(), AiEvaluation::STATUS_FAILED);

        $submission->forceFill([
            'locked_at' => now(),
            'locked_by_type' => 'system',
        ])->save();

        Queue::fake();

        $this->postJson(sprintf(self::ENDPOINT, $submission->id))
            ->assertStatus(409)
            ->assertJsonPath('code', 'SUBMISSION_LOCKED');

        Queue::assertNothingPushed();
        $this->assertSame(
            2,
            $this->evaluationsOf($submission)->where('status', AiEvaluation::STATUS_FAILED)->count(),
            '409 tidak boleh mengubah baris.'
        );
    }

    public function test_unknown_submission_returns_404(): void
    {
        $this->actingAsAssistantWithNilaiPermission();

        Queue::fake();

        $this->postJson(sprintf(self::ENDPOINT, 999999))
            ->assertStatus(404);

        Queue::assertNothingPushed();
    }

    public function test_assistant_without_nilai_permission_gets_403(): void
    {
        $role = Role::create(['name' => 'assistant-tanpa-nilai', 'guard_name' => 'asisten']);
        $assistant = Asisten::factory()->create(['role_id' => $role->id]);
        $assistant->assignRole($role);
        $this->actingAs($assistant, 'asisten');

        $this->allowPayloadConfig();
        $submission = $this->makeSubmissionWithAnswers(1);

        $this->setStatus($this->evaluationsOf($submission)->all(), AiEvaluation::STATUS_FAILED);

        Queue::fake();

        $this->postJson(sprintf(self::ENDPOINT, $submission->id))
            ->assertStatus(403);

        Queue::assertNothingPushed();
        $this->assertSame(1, $this->evaluationsOf($submission)->where('status', AiEvaluation::STATUS_FAILED)->count());
    }

    private function actingAsAssistantWithNilaiPermission(): Asisten
    {
        $role = Role::create([
            'name' => 'assistant-nilai-'.uniqid(),
            'guard_name' => 'asisten',
        ]);

        $permission = Permission::firstOrCreate([
            'name' => 'nilai-praktikan',
            'guard_name' => 'asisten',
        ]);

        $role->givePermissionTo($permission);

        $assistant = Asisten::factory()->create(['role_id' => $role->id]);
        $assistant->assignRole($role);

        $this->actingAs($assistant, 'asisten');

        return $assistant;
    }

    /**
     * Answers are written through the real writer, so the submission and its
     * evaluation rows are exactly the canonical ones produced in production.
     */
    private function makeSubmissionWithAnswers(int $answerCount): JawabanSubmission
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();

        $answers = [];

        for ($i = 0; $i < $answerCount; $i++) {
            $answers[] = [
                'soal_id' => SoalTp::factory()->for($modul)->create()->id,
                'jawaban' => 'Jawaban nomor '.$i.'.',
            ];
        }

        $this->createActiveVersionSet($modul);

        return $this->app->make(SubmissionWriter::class)->write(
            JawabanSubmission::TIPE_TP,
            $praktikan->id,
            $modul->id,
            $answers,
        );
    }

    private function createActiveVersionSet(Modul $modul): SoalVersionSet
    {
        $set = SoalVersionSet::query()->create([
            'modul_id' => $modul->id,
            'version_no' => 1,
            'status' => SoalVersionSet::STATUS_APPROVED,
            'bobot_total' => 100,
            'activated_at' => now()->utc(),
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

    private function allowPayloadConfig(): AiConfig
    {
        DB::table('ai_configs')->update(['is_active' => false]);

        return AiConfig::query()->create([
            'driver' => 'openai_compatible',
            'retrieval_top_k' => 0,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'allow_external_payload' => true,
            'ai_enabled' => true,
            'budget_tokens_per_modul' => 100000,
            'provider_policy_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    /**
     * @return Collection<int, AiEvaluation>
     */
    private function evaluationsOf(JawabanSubmission $submission): Collection
    {
        $answerIds = JawabanTp::query()
            ->where('submission_id', $submission->id)
            ->pluck('id')
            ->all();

        return AiEvaluation::query()
            ->where('jawaban_type', JawabanSubmission::TIPE_TP)
            ->whereIn('jawaban_id', $answerIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<AiEvaluation>  $rows
     */
    private function setStatus(array $rows, string $status): void
    {
        foreach ($rows as $row) {
            $row->forceFill([
                'status' => $status,
                'finished_at' => $status === AiEvaluation::STATUS_FAILED ? now() : null,
            ])->save();
        }
    }
}
