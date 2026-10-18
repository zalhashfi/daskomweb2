<?php

namespace Tests\Feature;

use App\Jobs\EvaluateAnswer;
use App\Models\AiConfig;
use App\Models\AiEvalMetric;
use App\Models\AiEvaluation;
use App\Models\Asisten;
use App\Models\AuditLog;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use App\Services\Ai\AiClientManager;
use App\Services\Ai\AiGate;
use App\Services\Ai\AiGateService;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET /api-v1/ai-gate + POST /api-v1/ai-gate/toggle + POST /api-v1/ai-gate/budget
 *
 *  - DDC can turn the gate OFF while 5 evaluations are queued → 200; the
 *    rows stay `queued` and no provider request is ever made;
 *  - KORDAS can GET the snapshot (200) but POST toggle → 403;
 *  - turning the gate ON while a modul still has non-terminal rows →
 *    409 CONFIG_CHANGE_MID_MODULE and the gate stays off;
 *  - both audit rows carry old value, new value and the reason;
 *  - a missing `reason` (or < 10 chars) → 422.
 */
class AiGateToggleTest extends TestCase
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
            'ai.openai_compatible.api_key' => 'sk-test-secret-gate',
            'ai.openai_compatible.chat_model' => self::CHAT_MODEL,
            'ai.openai_compatible.embed_model' => 'google/gemini-embedding-001',
            'ai.openai_compatible.chat_model_version' => 'gemini-flash-2026-10',
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
    // GET /ai-gate
    // ---------------------------------------------------------------------

    public function test_kordas_can_read_the_gate_snapshot(): void
    {
        $this->actingAsAssistant('KORDAS', ['view-ai-metrics']);

        $this->getJson('/api-v1/ai-gate')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.ai_enabled', true)
            ->assertJsonPath('data.allow_external_payload', true)
            ->assertJsonPath('data.budget_tokens_per_modul', 500000)
            ->assertJsonPath('data.provider_policy_verified_at', $this->config->provider_policy_verified_at->toIso8601String())
            ->assertJsonStructure([
                'status',
                'data' => [
                    'mae',
                    'korelasi',
                    'agreement',
                    'p95_wait_s',
                    'ai_enabled',
                    'allow_external_payload',
                    'provider_policy_verified_at',
                    'budget_tokens_per_modul',
                ],
            ]);
    }

    public function test_snapshot_reports_the_metrics_window(): void
    {
        AiEvalMetric::query()->whereNull('modul_id')->delete();

        AiEvalMetric::query()->create([
            'stat_date' => now()->subDay()->toDateString(),
            'modul_id' => null,
            'total' => 10,
            'done' => 10,
            'failed' => 0,
            'mae' => 8.0,
            'korelasi' => 0.8,
            'agreement' => 0.9,
            'p95_latency_ms' => 2000,
        ]);

        AiEvalMetric::query()->create([
            'stat_date' => now()->subDays(2)->toDateString(),
            'modul_id' => null,
            'total' => 30,
            'done' => 30,
            'failed' => 0,
            'mae' => 12.0,
            'korelasi' => 0.6,
            'agreement' => 0.7,
            'p95_latency_ms' => 4000,
        ]);

        $this->actingAsAssistant('KORDAS', ['view-ai-metrics']);

        $response = $this->getJson('/api-v1/ai-gate')->assertOk();

        $this->assertEqualsWithDelta(11.0, $response->json('data.mae'), 0.0001);
        $this->assertEqualsWithDelta(0.65, $response->json('data.korelasi'), 0.0001);
        $this->assertEqualsWithDelta(0.75, $response->json('data.agreement'), 0.0001);
        $this->assertEqualsWithDelta(4.0, $response->json('data.p95_wait_s'), 0.0001);
    }

    public function test_snapshot_requires_view_ai_metrics(): void
    {
        $this->actingAsAssistant('ATC', ['manage-modul-documents']);

        $this->getJson('/api-v1/ai-gate')->assertStatus(403);
    }

    // ---------------------------------------------------------------------
    // POST /ai-gate/toggle
    // ---------------------------------------------------------------------

    public function test_ddc_can_turn_the_gate_off_while_five_evaluations_are_queued(): void
    {
        $this->actingAsAssistant('DDC', ['manage-ai-gate', 'manage-ai-budget', 'view-ai-metrics']);

        $evaluations = $this->queuedEvaluations(5);
        $this->assertSame(5, AiEvaluation::query()->where('status', AiEvaluation::STATUS_QUEUED)->count());

        $this->assertTrue(AiConfig::active()->ai_enabled);

        $this->postJson('/api-v1/ai-gate/toggle', [
            'ai_enabled' => false,
            'reason' => 'Provider maintenance window tonight.',
        ])
            ->assertOk()
            ->assertJsonPath('data.ai_enabled', false)
            ->assertJsonPath('data.previous', true);

        $this->assertFalse(AiConfig::active()->ai_enabled);
        $this->assertSame(5, AiEvaluation::query()->where('status', AiEvaluation::STATUS_QUEUED)->count(), 'Antrean harus tetap queued.');

        Http::fake();

        foreach ($evaluations as $evaluation) {
            /*
             * The job reads the active config snapshot; point the fixture rows
             * at whichever snapshot is active so the gate check below runs on
             * the row the toggle actually flipped.
             */
            $evaluation->forceFill(['ai_config_id' => (int) AiConfig::active()->getKey()])->save();

            (new EvaluateAnswer($evaluation->fresh()))->handle(
                app(AiClientManager::class),
                app(AiGate::class),
                app(PromptBuilder::class),
            );
        }

        /*
         * Only the Inertia shell may be probed (Vite dev-server ping); any
         * other outgoing call — the provider above all — fails the test.
         */
        $outbound = collect(Http::recorded())->reject(
            static fn (array $entry): bool => str_contains($entry[0]->url(), '/@vite/client')
                || str_contains($entry[0]->url(), '/__vite_ping')
        );

        $this->assertSame(
            [],
            $outbound->map(static fn (array $entry): string => $entry[0]->url())->values()->all(),
            'Tidak boleh ada request keluar (provider) setelah gate dimatikan.'
        );

        foreach ($evaluations as $evaluation) {
            $fresh = $evaluation->fresh();

            $this->assertSame(AiEvaluation::STATUS_FAILED, $fresh->status);
            $this->assertSame('AI_DISABLED', $fresh->error_code);
            $this->assertSame(0, (int) $fresh->tokens_in + (int) $fresh->tokens_out);
            $this->assertNull($fresh->ai_score);
        }
    }

    public function test_toggle_audit_keeps_old_and_new_value_with_reason(): void
    {
        $assistant = $this->actingAsAssistant('DDC', ['manage-ai-gate']);

        $this->postJson('/api-v1/ai-gate/toggle', [
            'ai_enabled' => false,
            'reason' => 'Token spend above plan.',
        ])->assertOk();

        $log = AuditLog::query()->where('action', 'ai.gate.toggled')->latest('id')->first();

        $this->assertNotNull($log, 'Audit ai.gate.toggled harus tercatat.');
        $this->assertSame($assistant->id, $log->actor_id);
        $this->assertSame(['ai_enabled' => true], $log->metadata['old_value']);
        $this->assertSame(['ai_enabled' => false], $log->metadata['new_value']);
        $this->assertSame('Token spend above plan.', $log->metadata['reason']);
    }

    public function test_kordas_cannot_toggle_the_gate(): void
    {
        $this->actingAsAssistant('KORDAS', ['view-ai-metrics', 'regrade-ai', 'unlock-jawaban-submission']);

        $this->getJson('/api-v1/ai-gate')->assertOk();

        $this->postJson('/api-v1/ai-gate/toggle', [
            'ai_enabled' => false,
            'reason' => 'Trying to switch it off.',
        ])->assertStatus(403);

        $this->assertTrue(AiConfig::active()->ai_enabled);
        $this->assertSame(0, AuditLog::query()->where('action', 'ai.gate.toggled')->count());
    }

    public function test_turning_the_gate_on_mid_module_is_rejected(): void
    {
        $assistant = $this->actingAsAssistant('DDC', ['manage-ai-gate']);

        $this->gateOff($assistant);

        $this->queuedEvaluations(5);

        $this->postJson('/api-v1/ai-gate/toggle', [
            'ai_enabled' => true,
            'reason' => 'Resume now that the provider is back.',
        ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'CONFIG_CHANGE_MID_MODULE')
            ->assertJsonPath('active_modules', 1)
            ->assertJsonPath('pending_evaluations', 5);

        $this->assertFalse(AiConfig::active()->ai_enabled, 'Gate harus tetap mati.');
        $this->assertSame(5, AiEvaluation::query()->where('status', AiEvaluation::STATUS_QUEUED)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'ai.gate.toggled')->count(), 'Penolakan tidak menulis audit perubahan.');
    }

    public function test_turning_the_gate_on_is_allowed_once_the_queue_is_drained(): void
    {
        $assistant = $this->actingAsAssistant('DDC', ['manage-ai-gate']);

        $this->gateOff($assistant);

        AiEvaluation::query()->update(['status' => AiEvaluation::STATUS_DONE]);

        $this->postJson('/api-v1/ai-gate/toggle', [
            'ai_enabled' => true,
            'reason' => 'Resume after the weekend.',
        ])
            ->assertOk()
            ->assertJsonPath('data.ai_enabled', true)
            ->assertJsonPath('data.previous', false);

        $this->assertTrue(AiConfig::active()->ai_enabled);
    }

    public function test_toggle_without_reason_is_rejected(): void
    {
        $this->actingAsAssistant('DDC', ['manage-ai-gate']);

        $this->postJson('/api-v1/ai-gate/toggle', ['ai_enabled' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertTrue(AiConfig::active()->ai_enabled);
    }

    public function test_toggle_with_a_short_reason_is_rejected(): void
    {
        $this->actingAsAssistant('DDC', ['manage-ai-gate']);

        $this->postJson('/api-v1/ai-gate/toggle', [
            'ai_enabled' => false,
            'reason' => 'short',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertTrue(AiConfig::active()->ai_enabled);
    }

    public function test_toggle_requires_a_boolean_flag(): void
    {
        $this->actingAsAssistant('DDC', ['manage-ai-gate']);

        $this->postJson('/api-v1/ai-gate/toggle', [
            'ai_enabled' => 'maybe',
            'reason' => 'Providing an invalid value.',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ai_enabled');
    }

    // ---------------------------------------------------------------------
    // POST /ai-gate/budget
    // ---------------------------------------------------------------------

    public function test_ddc_updates_the_budget_and_audits_old_and_new_value(): void
    {
        $assistant = $this->actingAsAssistant('DDC', ['manage-ai-budget']);

        $this->postJson('/api-v1/ai-gate/budget', [
            'budget_tokens_per_modul' => 750000,
            'reason' => 'Raise the cap for the exam week.',
        ])
            ->assertOk()
            ->assertJsonPath('data.budget_tokens_per_modul', 750000)
            ->assertJsonPath('data.previous', 500000);

        $active = AiConfig::active();

        $this->assertSame(750000, $active->budget_tokens_per_modul);
        $this->assertTrue($active->ai_enabled, 'Perubahan budget tidak boleh mematikan gate.');

        $log = AuditLog::query()->where('action', 'ai.budget.updated')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($assistant->id, $log->actor_id);
        $this->assertSame(['budget_tokens_per_modul' => 500000], $log->metadata['old_value']);
        $this->assertSame(['budget_tokens_per_modul' => 750000], $log->metadata['new_value']);
        $this->assertSame('Raise the cap for the exam week.', $log->metadata['reason']);
    }

    public function test_budget_without_reason_is_rejected(): void
    {
        $this->actingAsAssistant('DDC', ['manage-ai-budget']);

        $this->postJson('/api-v1/ai-gate/budget', ['budget_tokens_per_modul' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson('/api-v1/ai-gate/budget', [
            'budget_tokens_per_modul' => 10,
            'reason' => 'too short',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(500000, AiConfig::active()->budget_tokens_per_modul);
    }

    public function test_kordas_cannot_change_the_budget(): void
    {
        $this->actingAsAssistant('KORDAS', ['view-ai-metrics']);

        $this->postJson('/api-v1/ai-gate/budget', [
            'budget_tokens_per_modul' => 1,
            'reason' => 'Trying to shrink the budget.',
        ])->assertStatus(403);

        $this->assertSame(500000, AiConfig::active()->budget_tokens_per_modul);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function actingAsAssistant(string $roleName, array $permissions): Asisten
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'asisten']);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'asisten']);
        }

        $role->syncPermissions($permissions);

        $assistant = Asisten::factory()->create(['role_id' => $role->id]);
        $assistant->assignRole($role);

        $this->actingAs($assistant, 'asisten');

        return $assistant;
    }

    /**
     * Turn the gate off through the real service (DDC), so the audit trail is
     * exactly the one the endpoint writes.
     */
    private function gateOff(Asisten $assistant): void
    {
        app(AiGateService::class)->toggle(false, 'Pausing the gate for the test.', $assistant->id);
    }

    /**
     * @return list<AiEvaluation>
     */
    private function queuedEvaluations(int $count): array
    {
        $evaluations = [];

        for ($index = 0; $index < $count; $index++) {
            $evaluations[] = $this->queuedEvaluation();
        }

        return $evaluations;
    }

    private function queuedEvaluation(): AiEvaluation
    {
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

        return AiEvaluation::query()->create([
            'jawaban_id' => $jawaban->id,
            'jawaban_type' => 'tp',
            'answer_hash' => hash('sha256', 'gate-'.$jawaban->id),
            'version_set_id' => $versionSet->id,
            'ai_config_id' => $this->config->id,
            'status' => AiEvaluation::STATUS_QUEUED,
        ]);
    }
}
