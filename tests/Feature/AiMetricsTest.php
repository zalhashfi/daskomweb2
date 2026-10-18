<?php

namespace Tests\Feature;

use App\Jobs\EvaluateAnswer;
use App\Models\AiConfig;
use App\Models\AiEvalMetric;
use App\Models\AiEvaluation;
use App\Models\Asisten;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Kelas;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use App\Services\Ai\AiClientManager;
use App\Services\Ai\AiGate;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Daily metrics rollup + backlog endpoint.
 *
 *  - `php artisan ai:aggregate-metrics --date=Y-m-d` turns the day's
 *    ai_evaluations into ONE global row (plus per-modul rows) whose
 *    total/done/failed match the DB, and is idempotent;
 *  - GET /api-v1/ai-backlog reports queued/running/failed/oldest_age_s per
 *    modul and is guarded by `view-ai-metrics` (403 otherwise).
 */
class AiMetricsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://gateway.test';

    private const CHAT_MODEL = 'google/gemini-flash';

    private Modul $modul;

    private AiConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        Auth::guard('web')->logout();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config([
            'ai.openai_compatible.base_url' => self::BASE_URL,
            'ai.openai_compatible.api_key' => 'sk-test-secret-metrics',
            'ai.openai_compatible.chat_model' => self::CHAT_MODEL,
            'ai.openai_compatible.embed_model' => 'google/gemini-embedding-001',
        ]);

        $this->modul = Modul::factory()->create();

        $this->config = AiConfig::query()->create([
            'driver' => 'openai_compatible',
            'base_url' => self::BASE_URL,
            'chat_model' => self::CHAT_MODEL,
            'retrieval_top_k' => 0,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'allow_external_payload' => true,
            'ai_enabled' => true,
            'budget_tokens_per_modul' => 500000,
            'provider_policy_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------------
    // ai:aggregate-metrics
    // ---------------------------------------------------------------------

    public function test_command_writes_one_global_row_after_an_evaluation_is_stored(): void
    {
        $day = '2026-10-10';
        $this->travelTo($day.' 10:00:00');

        Http::fake([self::BASE_URL.'/v1/chat/completions' => Http::response($this->chatCompletion(), 200)]);

        $evaluation = $this->queuedEvaluation();
        (new EvaluateAnswer($evaluation))->handle(
            app(AiClientManager::class),
            app(AiGate::class),
            app(PromptBuilder::class),
        );

        $this->assertSame(AiEvaluation::STATUS_DONE, $evaluation->fresh()->status);
        $this->assertSame(0, AiEvalMetric::query()->count());

        $this->artisan('ai:aggregate-metrics', ['--date' => $day])
            ->expectsOutputToContain('total=1 done=1 failed=0')
            ->assertExitCode(0);

        $global = AiEvalMetric::query()->whereNull('modul_id')->get();

        $this->assertCount(1, $global, 'Harus ada tepat satu baris metrik global.');
        $metric = $global->first();
        $this->assertSame($day, $metric->stat_date->toDateString());
        $this->assertSame(1, $metric->total);
        $this->assertSame(1, $metric->done);
        $this->assertSame(0, $metric->failed);
        $this->assertSame('0.9000', (string) $metric->avg_confidence);
        $this->assertSame((int) $evaluation->fresh()->latency_ms, $metric->p50_latency_ms);
        $this->assertSame((int) $evaluation->fresh()->latency_ms, $metric->p95_latency_ms);
        $this->assertNull($metric->mae, 'Tidak ada nilai manual, jadi MAE null.');
        $this->assertNull($metric->korelasi);
        $this->assertNull($metric->agreement);

        $perModul = AiEvalMetric::query()->where('modul_id', $this->modul->id)->firstOrFail();
        $this->assertSame(1, $perModul->done);
    }

    public function test_command_counts_done_and_failed_rows(): void
    {
        $day = '2026-10-11';
        $this->travelTo($day.' 09:00:00');

        $this->queuedEvaluation(['status' => AiEvaluation::STATUS_DONE, 'answer_hash' => hash('sha256', 'm-done')]);
        $this->queuedEvaluation(['status' => AiEvaluation::STATUS_FAILED, 'answer_hash' => hash('sha256', 'm-failed')]);
        $this->queuedEvaluation(['status' => AiEvaluation::STATUS_QUEUED, 'answer_hash' => hash('sha256', 'm-queued')]);
        // Another day must not leak into the rollup.
        $this->queuedEvaluation([
            'status' => AiEvaluation::STATUS_DONE,
            'answer_hash' => hash('sha256', 'm-other-day'),
        ])->forceFill(['created_at' => '2026-10-09 23:59:59'])->save();

        $this->artisan('ai:aggregate-metrics', ['--date' => $day])->assertExitCode(0);

        $metric = AiEvalMetric::query()->whereNull('modul_id')->firstOrFail();
        $this->assertSame(3, $metric->total);
        $this->assertSame(1, $metric->done);
        $this->assertSame(1, $metric->failed);
    }

    public function test_command_is_idempotent_for_the_same_day(): void
    {
        $day = '2026-10-12';
        $this->travelTo($day.' 09:00:00');

        $this->queuedEvaluation(['status' => AiEvaluation::STATUS_DONE, 'answer_hash' => hash('sha256', 'i-1')]);

        $this->artisan('ai:aggregate-metrics', ['--date' => $day])->assertExitCode(0);
        $this->artisan('ai:aggregate-metrics', ['--date' => $day])->assertExitCode(0);

        $this->assertSame(1, AiEvalMetric::query()->whereNull('modul_id')->count());
        $this->assertSame(1, AiEvalMetric::query()->where('modul_id', $this->modul->id)->count());
        $this->assertSame(1, AiEvalMetric::query()->whereNull('modul_id')->firstOrFail()->total);
    }

    public function test_command_defaults_to_yesterday(): void
    {
        $this->travelTo('2026-10-13 08:00:00');

        $this->queuedEvaluation([
            'status' => AiEvaluation::STATUS_DONE,
            'answer_hash' => hash('sha256', 'y-1'),
        ])->forceFill(['created_at' => '2026-10-12 20:00:00'])->save();

        $this->artisan('ai:aggregate-metrics')->assertExitCode(0);

        $metric = AiEvalMetric::query()->whereNull('modul_id')->firstOrFail();
        $this->assertSame('2026-10-12', $metric->stat_date->toDateString());
        $this->assertSame(1, $metric->total);
    }

    public function test_command_rejects_an_invalid_date(): void
    {
        $this->artisan('ai:aggregate-metrics', ['--date' => 'kemarin'])
            ->assertExitCode(1);

        $this->assertSame(0, AiEvalMetric::query()->count());
    }

    public function test_mae_korelasi_and_agreement_use_manual_grades(): void
    {
        $day = '2026-10-14';
        $this->travelTo($day.' 09:00:00');

        // Manual grades below come from nilais.tp.
        $this->queuedEvaluation([
            'status' => AiEvaluation::STATUS_DONE,
            'verdict' => 'sesuai',
            'ai_score' => 90,
            'manual_score' => 80,
            'answer_hash' => hash('sha256', 's-1'),
        ]);
        $this->queuedEvaluation([
            'status' => AiEvaluation::STATUS_DONE,
            'verdict' => 'tidak_sesuai',
            'ai_score' => 60,
            'manual_score' => 40,
            'answer_hash' => hash('sha256', 's-2'),
        ]);

        $this->artisan('ai:aggregate-metrics', ['--date' => $day])->assertExitCode(0);

        $metric = AiEvalMetric::query()->whereNull('modul_id')->firstOrFail();
        // |90-80| = 10 and |60-40| = 20 -> mean 15; both pairs rise together.
        $this->assertSame('15.0000', (string) $metric->mae);
        $this->assertSame('1.0000', (string) $metric->korelasi);
        // 80 is `sesuai` and 40 is `sebagian_sesuai`: one of two agrees.
        $this->assertSame('0.5000', (string) $metric->agreement);
    }

    // ---------------------------------------------------------------------
    // GET /api-v1/ai-backlog
    // ---------------------------------------------------------------------

    public function test_backlog_reports_queued_and_failed_per_modul(): void
    {
        $this->actingAsAssistant('KORDAS', ['view-ai-metrics']);

        $this->queuedEvaluation([
            'answer_hash' => hash('sha256', 'b-queued-1'),
            'created_at' => now()->subSeconds(120),
        ])->forceFill(['created_at' => now()->subSeconds(120)])->save();
        $this->queuedEvaluation([
            'answer_hash' => hash('sha256', 'b-queued-2'),
            'created_at' => now()->subSeconds(60),
        ])->forceFill(['created_at' => now()->subSeconds(60)])->save();
        $this->queuedEvaluation([
            'status' => AiEvaluation::STATUS_FAILED,
            'answer_hash' => hash('sha256', 'b-failed'),
        ]);

        $response = $this->getJson('/api-v1/ai-backlog');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.0.modul_id', $this->modul->id)
            ->assertJsonPath('data.0.queued', 2)
            ->assertJsonPath('data.0.running', 0)
            ->assertJsonPath('data.0.failed', 1);

        $age = (int) $response->json('data.0.oldest_age_s');
        $this->assertGreaterThanOrEqual(120, $age, 'oldest_age_s harus dihitung dari baris non-terminal tertua.');
        $this->assertLessThan(180, $age);
    }

    public function test_backlog_counts_processing_as_running_and_ignores_terminal_rows(): void
    {
        $this->actingAsAssistant('KORDAS', ['view-ai-metrics']);

        $this->queuedEvaluation(['status' => 'processing', 'answer_hash' => hash('sha256', 'r-1')]);
        $this->queuedEvaluation(['status' => AiEvaluation::STATUS_DONE, 'answer_hash' => hash('sha256', 'r-done')]);

        $response = $this->getJson('/api-v1/ai-backlog');

        $response->assertOk()
            ->assertJsonPath('data.0.running', 1)
            ->assertJsonPath('data.0.queued', 0)
            ->assertJsonPath('data.0.failed', 0);
    }

    public function test_backlog_is_empty_when_nothing_is_evaluated(): void
    {
        $this->actingAsAssistant('KORDAS', ['view-ai-metrics']);

        $this->getJson('/api-v1/ai-backlog')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(0, 'data');
    }

    public function test_backlog_forbids_a_role_without_view_ai_metrics(): void
    {
        $this->actingAsAssistant('ATC', ['manage-modul-documents']);

        $this->queuedEvaluation();

        $this->getJson('/api-v1/ai-backlog')->assertStatus(403);
    }

    public function test_backlog_requires_authentication(): void
    {
        $this->getJson('/api-v1/ai-backlog')->assertUnauthorized();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function actingAsAssistant(string $roleName, array $permissions): Asisten
    {
        $assistant = $this->makeAssistantWithPermissions($roleName, $permissions);

        $this->actingAs($assistant, 'asisten');

        return $assistant;
    }

    /**
     * An assistant holding `view-ai-metrics`, used as the grader in fixtures.
     */
    private function metricViewer(): Asisten
    {
        return $this->makeAssistantWithPermissions('KORDAS', ['view-ai-metrics']);
    }

    private function makeAssistantWithPermissions(string $roleName, array $permissions): Asisten
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'asisten']);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'asisten']);
        }

        $role->syncPermissions($permissions);

        $assistant = Asisten::factory()->create(['role_id' => $role->id]);
        $assistant->assignRole($role);

        return $assistant;
    }

    /**
     * A queued evaluation with its answer row; `manual_score` (when given)
     * is written as the assistant's TP grade in `nilais`.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function queuedEvaluation(array $attributes = []): AiEvaluation
    {
        $manualScore = $attributes['manual_score'] ?? null;
        unset($attributes['manual_score']);

        $praktikan = Praktikan::factory()->create();
        $soal = SoalTp::factory()->create(['modul_id' => $this->modul->id]);
        $versionSet = SoalVersionSet::query()->firstOrCreate(
            ['modul_id' => $this->modul->id, 'version_no' => 1],
            [
                'status' => SoalVersionSet::STATUS_APPROVED,
                'bobot_total' => 100,
                'activated_at' => now(),
            ],
        );

        SoalVersionItem::create([
            'version_set_id' => $versionSet->id,
            'soal_type' => SoalVersionItem::SOAL_TP,
            'soal_id' => $soal->id,
            'bobot' => 100,
            'max_score' => 100,
            'teks_snapshot' => $soal->soal,
            'jawaban_standar' => 'Kunci jawaban.',
            'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
        ]);

        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $this->modul->id,
            'tipe' => 'tp',
            'attempt_no' => 1,
        ]);

        $jawaban = JawabanTp::query()->create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $soal->id,
            'modul_id' => $this->modul->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Jawaban untuk soal '.$soal->id.'.',
        ]);

        if ($manualScore !== null) {
            DB::table('nilais')->insert([
                'asisten_id' => $this->metricViewer()->id,
                'kelas_id' => Kelas::factory()->create()->id,
                'tp' => $manualScore,
                'ta' => 0,
                'd1' => 0,
                'd2' => 0,
                'd3' => 0,
                'd4' => 0,
                'l1' => 0,
                'l2' => 0,
                'avg' => 0,
                'modul_id' => $this->modul->id,
                'praktikan_id' => $praktikan->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return AiEvaluation::query()->create(array_merge([
            'jawaban_id' => $jawaban->id,
            'jawaban_type' => 'tp',
            'answer_hash' => hash('sha256', 'metrics-'.$jawaban->id),
            'version_set_id' => $versionSet->id,
            'ai_config_id' => $this->config->id,
            'status' => AiEvaluation::STATUS_QUEUED,
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function chatCompletion(): array
    {
        return [
            'id' => 'chatcmpl-metrics-1',
            'object' => 'chat.completion',
            'model' => self::CHAT_MODEL,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => (string) json_encode([
                            'verdict' => 'sesuai',
                            'score' => 90,
                            'confidence' => 0.9,
                            'alasan' => 'Sesuai kunci.',
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ];
    }
}
