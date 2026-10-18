<?php

namespace Tests\Feature;

use App\Jobs\EvaluateAnswer;
use App\Models\AiBudgetUsage;
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
use Database\Seeders\AiPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * POST /api-v1/ai-regrade  {"modul_id": N, "priority": "high"|"normal"}
 *
 * - KORDAS (holds `regrade-ai`) → 202 `{"queued": N}` where N is the number
 *   of non-attachment answers of the modul (one job each)
 * - `priority: high` reaches the job as `$job->priority === 'high'`
 * - old `done` rows keep their verdict/score (never overwritten)
 * - gate off → 409 `AI_GATE_DISABLED`, nothing queued / nothing changed
 * - exhausted budget → 409 `BUDGET_EXCEEDED`
 * - assistant without `regrade-ai` → 403
 */
class AiRegradeTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api-v1/ai-regrade';

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_kordas_regrades_every_latest_answer_of_the_modul(): void
    {
        $this->actingAsKordas();

        $this->allowPayloadConfig();

        $modul = Modul::factory()->create();

        $submissionA = $this->makeSubmission(Praktikan::factory()->create(), 3, $modul);
        $submissionB = $this->makeSubmission(Praktikan::factory()->create(), 2, $modul);

        $this->assertCount(5, $this->evaluationsOf($submissionA)->merge($this->evaluationsOf($submissionB)));

        Queue::fake();

        $response = $this->postJson(self::ENDPOINT, ['modul_id' => $modul->id]);

        $response->assertStatus(202);
        $response->assertExactJson(['queued' => 5]);

        Queue::assertPushed(EvaluateAnswer::class, 5);
    }

    public function test_high_priority_is_carried_by_the_dispatched_job(): void
    {
        $this->actingAsKordas();

        $this->allowPayloadConfig();
        $submission = $this->makeSubmission(Praktikan::factory()->create(), 2);

        Queue::fake();

        $this->postJson(self::ENDPOINT, ['modul_id' => $submission->modul_id, 'priority' => 'high'])
            ->assertStatus(202)
            ->assertExactJson(['queued' => 2]);

        Queue::assertPushed(EvaluateAnswer::class, fn (EvaluateAnswer $job): bool => $job->priority === 'high');
        Queue::assertPushed(EvaluateAnswer::class, 2);
    }

    public function test_default_priority_is_normal(): void
    {
        $this->actingAsKordas();

        $this->allowPayloadConfig();
        $submission = $this->makeSubmission(Praktikan::factory()->create(), 1);

        Queue::fake();

        $this->postJson(self::ENDPOINT, ['modul_id' => $submission->modul_id])
            ->assertStatus(202)
            ->assertExactJson(['queued' => 1]);

        Queue::assertPushed(EvaluateAnswer::class, fn (EvaluateAnswer $job): bool => $job->priority === 'normal');
    }

    public function test_done_rows_are_kept_and_failed_rows_are_requeued(): void
    {
        $this->actingAsKordas();

        $this->allowPayloadConfig();
        $submission = $this->makeSubmission(Praktikan::factory()->create(), 3);

        $rows = $this->evaluationsOf($submission);

        $done = $rows[0]->forceFill([
            'status' => AiEvaluation::STATUS_DONE,
            'verdict' => 'benar',
            'ai_score' => 87.5,
            'alasan' => 'Penilaian lama.',
            'finished_at' => now()->subHour(),
        ]);
        $done->save();

        $failed = $rows[1]->forceFill([
            'status' => AiEvaluation::STATUS_FAILED,
            'error_code' => 'EXTERNAL_UNAVAILABLE',
            'finished_at' => now()->subHour(),
        ]);
        $failed->save();

        Queue::fake();

        $this->postJson(self::ENDPOINT, ['modul_id' => $submission->modul_id])
            ->assertStatus(202)
            ->assertExactJson(['queued' => 2]);

        $freshDone = $done->fresh();

        $this->assertSame(AiEvaluation::STATUS_DONE, $freshDone->status);
        $this->assertSame('benar', $freshDone->verdict);
        $this->assertSame('87.50', $freshDone->ai_score);
        $this->assertSame('Penilaian lama.', $freshDone->alasan);
        $this->assertNotNull($freshDone->finished_at);

        $freshFailed = $failed->fresh();

        $this->assertSame(AiEvaluation::STATUS_QUEUED, $freshFailed->status);
        $this->assertNull($freshFailed->error_code);
        $this->assertNull($freshFailed->finished_at);

        $pushedIds = collect(Queue::pushed(EvaluateAnswer::class))
            ->map(fn (EvaluateAnswer $job): int => (int) $job->evaluation->getKey())
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$failed->id, $rows[2]->id], $pushedIds);
        $this->assertSame(3, AiEvaluation::query()->count(), 'Regrade tidak boleh menambah baris.');
    }

    public function test_attachment_answers_are_not_counted_nor_queued(): void
    {
        $this->actingAsKordas();

        $this->allowPayloadConfig();

        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();

        $soalIds = [
            SoalTp::factory()->for($modul)->create()->id,
            SoalTp::factory()->for($modul)->create()->id,
            SoalTp::factory()->for($modul)->create([
                'enable_file_upload' => true,
            ])->id,
        ];

        $this->syncActiveVersionSet($modul, $soalIds);

        $submission = $this->app->make(SubmissionWriter::class)->write(
            JawabanSubmission::TIPE_TP,
            $praktikan->id,
            $modul->id,
            array_map(fn (int $soalId): array => [
                'soal_id' => $soalId,
                'jawaban' => 'Jawaban soal '.$soalId.'.',
            ], $soalIds),
        );

        $this->assertSame(
            1,
            $this->evaluationsOf($submission)->where('status', AiEvaluation::STATUS_MANUAL_REQUIRED)->count(),
            'Satu soal lampiran tersimpan manual_required.'
        );

        Queue::fake();

        $this->postJson(self::ENDPOINT, ['modul_id' => $modul->id])
            ->assertStatus(202)
            ->assertExactJson(['queued' => 2]);

        Queue::assertPushed(EvaluateAnswer::class, 2);
    }

    public function test_disabled_gate_returns_409_and_queues_nothing(): void
    {
        $this->actingAsKordas();

        $config = $this->allowPayloadConfig();
        $submission = $this->makeSubmission(Praktikan::factory()->create(), 2);

        $config->forceFill(['ai_enabled' => false])->save();

        Queue::fake();

        $this->postJson(self::ENDPOINT, ['modul_id' => $submission->modul_id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'AI_DISABLED');

        Queue::assertNothingPushed();
        $this->assertSame(
            2,
            $this->evaluationsOf($submission)->count(),
            '409 tidak boleh membuat atau menghapus baris.'
        );
    }

    public function test_exhausted_budget_returns_409(): void
    {
        $this->actingAsKordas();

        $config = $this->allowPayloadConfig();
        $submission = $this->makeSubmission(Praktikan::factory()->create(), 2);

        AiBudgetUsage::query()->create([
            'modul_id' => $submission->modul_id,
            'ai_config_id' => $config->id,
            'tokens_used' => 100000,
        ]);

        Queue::fake();

        $this->postJson(self::ENDPOINT, ['modul_id' => $submission->modul_id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'BUDGET_EXCEEDED');

        Queue::assertNothingPushed();
    }

    public function test_assistant_without_regrade_permission_gets_403(): void
    {
        $role = Role::create(['name' => 'assistant-tanpa-regrade', 'guard_name' => 'asisten']);
        $assistant = Asisten::factory()->create(['role_id' => $role->id]);
        $assistant->assignRole($role);
        $this->actingAs($assistant, 'asisten');

        $this->allowPayloadConfig();
        $submission = $this->makeSubmission(Praktikan::factory()->create(), 1);

        Queue::fake();

        $this->postJson(self::ENDPOINT, ['modul_id' => $submission->modul_id])
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    public function test_unknown_modul_is_rejected(): void
    {
        $this->actingAsKordas();

        Queue::fake();

        $this->postJson(self::ENDPOINT, ['modul_id' => 999999])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    private function actingAsKordas(): Asisten
    {
        $this->seed(AiPermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::where('name', 'KORDAS')->where('guard_name', 'asisten')->firstOrFail();
        $assistant = Asisten::factory()->create(['role_id' => $role->id]);
        $assistant->assignRole($role);

        $this->actingAs($assistant, 'asisten');

        return $assistant;
    }

    private function makeSubmission(Praktikan $praktikan, int $answerCount, ?Modul $modul = null): JawabanSubmission
    {
        $modul ??= Modul::factory()->create();

        $soalIds = [];

        for ($i = 0; $i < $answerCount; $i++) {
            $soalIds[] = SoalTp::factory()->for($modul)->create()->id;
        }

        $this->syncActiveVersionSet($modul, $soalIds);

        return $this->app->make(SubmissionWriter::class)->write(
            JawabanSubmission::TIPE_TP,
            $praktikan->id,
            $modul->id,
            array_map(fn (int $soalId): array => [
                'soal_id' => $soalId,
                'jawaban' => 'Jawaban soal '.$soalId.'.',
            ], $soalIds),
        );
    }

    /**
     * @param  list<int>  $soalIds
     */
    /**
     * One active set per modul: created on first use, its items then topped up
     * with every soal the modul gained since.
     *
     * @param  list<int>  $soalIds
     */
    private function syncActiveVersionSet(Modul $modul, array $soalIds): SoalVersionSet
    {
        $set = SoalVersionSet::query()
            ->where('modul_id', $modul->id)
            ->orderBy('id')
            ->first();

        if ($set === null) {
            $set = SoalVersionSet::query()->create([
                'modul_id' => $modul->id,
                'version_no' => 1,
                'status' => SoalVersionSet::STATUS_APPROVED,
                'bobot_total' => 100,
                'activated_at' => now()->utc(),
            ]);
        }

        $existing = SoalVersionItem::query()
            ->where('version_set_id', $set->id)
            ->pluck('soal_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach (SoalTp::query()->whereIn('id', $soalIds)->get() as $soal) {
            if (in_array((int) $soal->id, $existing, true)) {
                continue;
            }

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
}
