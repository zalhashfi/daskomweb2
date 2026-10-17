<?php

namespace Tests\Feature;

use App\Exceptions\ProviderPolicyUnverifiedException;
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
use App\Services\Ai\AiClientManager;
use App\Services\Ai\AiGate;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T08y — `ai:verify-provider --confirm-paid-no-training`.
 *
 * Verifying the provider policy is an operator decision, so without the
 * explicit flag the command must not reach the provider, must leave
 * provider_policy_verified_at empty and must exit 1. With the flag it
 * validates the configured chat model against GET /v1/models first, and only
 * a valid model stamps the ACTIVE ai_configs row and writes the audit
 * `ai.provider.policy_verified` (host/model/flag, never the token).
 */
class AiProviderPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://gateway.test';

    private const API_KEY = 'sk-test-secret-policy-9d2f';

    private const CHAT_MODEL = 'google/gemini-flash';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        config([
            'ai.openai_compatible.base_url' => self::BASE_URL,
            'ai.openai_compatible.api_key' => self::API_KEY,
            'ai.openai_compatible.chat_model' => self::CHAT_MODEL,
        ]);
    }

    private function modelsUrl(): string
    {
        return self::BASE_URL.'/v1/models';
    }

    private function chatUrl(): string
    {
        return self::BASE_URL.'/v1/chat/completions';
    }

    /**
     * @param  list<string>  $ids
     */
    private function fakeCatalog(array $ids): void
    {
        Http::fake([
            $this->modelsUrl() => Http::response([
                'object' => 'list',
                'data' => array_map(fn (string $id): array => ['id' => $id, 'owned_by' => 'provider'], $ids),
            ], 200),
        ]);
    }

    private function runCommand(bool $confirm): int
    {
        return Artisan::call('ai:verify-provider', $confirm ? ['--confirm-paid-no-training' => true] : []);
    }

    /**
     * The active row exactly as AiGateTest provisions an enabled config.
     */
    private function activeConfig(): AiConfig
    {
        return AiConfig::query()->create([
            'driver' => 'openai_compatible',
            'base_url' => self::BASE_URL,
            'base_url_host' => 'gateway.test',
            'chat_model' => self::CHAT_MODEL,
            'retrieval_top_k' => 4,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'ai_enabled' => true,
            'allow_external_payload' => true,
            'provider_policy_verified_at' => null,
            'budget_tokens_per_modul' => 500000,
            'is_active' => true,
        ]);
    }

    private function runJob(AiEvaluation $evaluation): void
    {
        (new EvaluateAnswer($evaluation))->handle(
            app(AiClientManager::class),
            app(AiGate::class),
            app(PromptBuilder::class),
        );
    }

    // ---------------------------------------------------------------------
    // Without the flag
    // ---------------------------------------------------------------------

    public function test_without_flag_no_provider_call_and_no_column_write(): void
    {
        Http::fake();
        $config = $this->activeConfig();

        $exitCode = $this->runCommand(false);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--confirm-paid-no-training', $output);
        $this->assertNull($config->fresh()->provider_policy_verified_at);
        Http::assertNothingSent();
    }

    public function test_without_flag_gate_and_job_still_reject_with_provider_policy_unverified(): void
    {
        Http::fake();
        $this->activeConfig();

        $this->assertSame(1, $this->runCommand(false));

        try {
            app(AiGate::class)->assertEnabled(AiConfig::active());
            $this->fail('AiGate harus melempar ProviderPolicyUnverifiedException.');
        } catch (ProviderPolicyUnverifiedException $e) {
            $this->assertSame('PROVIDER_POLICY_UNVERIFIED', $e->getErrorCode());
        }

        [$evaluation, $config] = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $evaluation->refresh();
        $this->assertSame(AiEvaluation::STATUS_FAILED, $evaluation->status);
        $this->assertSame('PROVIDER_POLICY_UNVERIFIED', $evaluation->error_code);
        $this->assertNull($config->fresh()->provider_policy_verified_at);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // With the flag
    // ---------------------------------------------------------------------

    public function test_with_flag_stamps_active_row_and_writes_audit_without_token(): void
    {
        $this->fakeCatalog([self::CHAT_MODEL]);
        $this->activeConfig();

        Log::spy();

        $exitCode = $this->runCommand(true);

        $this->assertSame(0, $exitCode, Artisan::output());

        // Re-read instead of refresh(): the revision mechanism may replace the
        // row instance the caller holds.
        $config = AiConfig::active();
        $this->assertNotNull($config->provider_policy_verified_at);
        $this->assertTrue($config->provider_policy_verified_at->lessThanOrEqualTo(now()));
        $this->assertTrue($config->provider_policy_verified_at->greaterThanOrEqualTo(now()->subMinute()));
        $this->assertTrue($config->is_active);

        // Exactly one request: GET /v1/models carrying the bearer token.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === $this->modelsUrl()
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY));

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            $this->assertSame('ai.provider.policy_verified', $message);
            $this->assertSame('gateway.test', $context['host'] ?? null);
            $this->assertSame(self::CHAT_MODEL, $context['model'] ?? null);
            $this->assertTrue($context['confirm_paid_no_training'] ?? null);

            $serialized = json_encode([$message, $context], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertStringNotContainsString(self::API_KEY, (string) $serialized);
            $this->assertStringNotContainsString('sk-test', (string) $serialized);
            $this->assertArrayNotHasKey('api_key', $context);
            $this->assertArrayNotHasKey('token', $context);

            return true;
        })->once();
    }

    public function test_after_successful_flag_gate_passes_and_job_may_reach_provider(): void
    {
        $this->fakeCatalog([self::CHAT_MODEL]);
        $this->activeConfig();

        $this->assertSame(0, $this->runCommand(true));

        app(AiGate::class)->assertEnabled(AiConfig::active());

        // A second fake replaces the catalogue fake for the job itself.
        Http::fake([
            $this->chatUrl() => Http::response([
                'id' => 'chatcmpl-1',
                'model' => self::CHAT_MODEL,
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'verdict' => 'benar',
                        'score' => 100,
                        'confidence' => 0.9,
                        'alasan' => 'Sesuai kunci.',
                    ])],
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        [$evaluation] = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $evaluation->refresh();
        $this->assertNotSame('PROVIDER_POLICY_UNVERIFIED', $evaluation->error_code);
        Http::assertSent(fn (Request $request): bool => $request->url() === $this->chatUrl());
        Http::assertNotSent(fn (Request $request): bool => $request->url() === $this->modelsUrl());
    }

    // ---------------------------------------------------------------------
    // Invalid model / unreachable catalogue
    // ---------------------------------------------------------------------

    public function test_invalid_model_exits_one_and_leaves_column_null(): void
    {
        $this->fakeCatalog(['openai/gpt-4o']);
        $config = $this->activeConfig();

        Log::spy();

        $exitCode = $this->runCommand(true);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(self::CHAT_MODEL, Artisan::output());
        $this->assertNull($config->fresh()->provider_policy_verified_at);
        Log::shouldNotHaveReceived('info');
    }

    public function test_unreachable_catalogue_exits_one_and_leaves_column_null(): void
    {
        Http::fake([$this->modelsUrl() => Http::response(['error' => 'unauthorized'], 401)]);
        $config = $this->activeConfig();

        $this->assertSame(1, $this->runCommand(true));
        $this->assertNull($config->fresh()->provider_policy_verified_at);
    }

    public function test_command_is_registered_and_accepts_the_confirmation_flag(): void
    {
        $this->assertArrayHasKey('ai:verify-provider', Artisan::all());

        $definition = Artisan::all()['ai:verify-provider']->getDefinition();

        $this->assertTrue($definition->hasOption('confirm-paid-no-training'));
    }

    // ---------------------------------------------------------------------
    // Helper: one queued evaluation against the active config
    // ---------------------------------------------------------------------

    /**
     * @return array{0: AiEvaluation, 1: AiConfig}
     */
    private function queuedEvaluation(): array
    {
        $config = AiConfig::active();

        $modul = Modul::factory()->create();
        $praktikan = Praktikan::factory()->create();

        $soal = SoalTp::factory()->create([
            'modul_id' => $modul->id,
            'soal' => 'Jelaskan fungsi resistor pada rangkaian seri.',
        ]);

        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => 'tp',
            'attempt_no' => 1,
        ]);

        $jawaban = JawabanTp::query()->create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $soal->id,
            'modul_id' => $modul->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Resistor membatasi arus listrik pada rangkaian.',
        ]);

        $versionSet = SoalVersionSet::create([
            'modul_id' => $modul->id,
            'version_no' => 1,
            'status' => SoalVersionSet::STATUS_APPROVED,
            'bobot_total' => 100,
            'activated_at' => now(),
        ]);

        SoalVersionItem::create([
            'version_set_id' => $versionSet->id,
            'soal_type' => SoalVersionItem::SOAL_TP,
            'soal_id' => $soal->id,
            'bobot' => 100,
            'max_score' => 100,
            'jawaban_standar' => 'Resistor membatasi arus listrik dan membagi tegangan.',
            'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
            'teks_snapshot' => $soal->soal,
        ]);

        $evaluation = AiEvaluation::query()->create([
            'jawaban_id' => $jawaban->id,
            'jawaban_type' => 'tp',
            'answer_hash' => hash('sha256', (string) $jawaban->jawaban),
            'version_set_id' => $versionSet->id,
            'ai_config_id' => $config->id,
            'status' => 'queued',
        ]);

        return [$evaluation, $config];
    }
}
