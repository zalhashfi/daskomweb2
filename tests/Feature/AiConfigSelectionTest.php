<?php

namespace Tests\Feature;

use App\Exceptions\ExternalPayloadDisabledException;
use App\Models\AiConfig;
use App\Models\AuditLog;
use Database\Seeders\AiConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T18c - model selection, digests, embedding dimension and the external
 * payload guard.
 *
 * Covers config/ai.php defaults, the AiConfigSeeder snapshot (inert by
 * default, idempotent, audited without secrets) and
 * AiConfig::assertPayloadPolicy() (fail-closed whitelist).
 */
class AiConfigSelectionTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'http://127.0.0.1:20128';

    private const CHAT_MODEL = 'gemini/gemini-flash';

    private const EMBED_MODEL = 'gemini/gemini-embedding-001';

    private const EMBED_DIMENSION = 3072;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        config([
            'ai.openai_compatible.base_url' => self::BASE_URL,
            'ai.openai_compatible.api_key' => 'sk-test-super-secret-token',
            'ai.openai_compatible.chat_model' => self::CHAT_MODEL,
            'ai.openai_compatible.chat_model_version' => 'gemini-flash-2026-09',
            'ai.embed_profiles.ninerouter.name' => self::EMBED_MODEL,
            'ai.embed_profiles.ninerouter.dimension' => self::EMBED_DIMENSION,
            'ai.budget_tokens_per_modul' => 500000,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function configModel(array $attributes = []): AiConfig
    {
        return new AiConfig(array_merge([
            'driver' => 'openai_compatible',
            'embed_driver' => 'ninerouter',
        ], $attributes));
    }

    public function seedAiConfig(): void
    {
        $this->seed(AiConfigSeeder::class);
    }

    public function test_config_defaults_match_spec(): void
    {
        $this->assertSame('openai_compatible', config('ai.default_driver'));
        $this->assertSame(10000, config('ai.max_answer_chars'));
        $this->assertSame(90, config('ai.openai_compatible.timeout'));
    }

    public function test_config_reads_environment_with_expected_fallbacks(): void
    {
        $defaults = require base_path('config/ai.php');
        $configured = config('ai');

        // Chat/embed defaults survive even when the env file is silent.
        $this->assertArrayHasKey('default_driver', $configured);
        $this->assertArrayHasKey('openai_compatible', $configured);
        $this->assertArrayHasKey('base_url', $configured['openai_compatible']);
        $this->assertArrayHasKey('api_key', $configured['openai_compatible']);
        $this->assertArrayHasKey('chat_model', $configured['openai_compatible']);
        $this->assertArrayHasKey('chat_model_version', $configured['openai_compatible']);
        $this->assertArrayHasKey('embed_model', $configured['openai_compatible']);
        $this->assertArrayHasKey('embed_profiles', $configured);
        $this->assertArrayHasKey('ninerouter', $configured['embed_profiles']);
        $this->assertArrayHasKey('name', $configured['embed_profiles']['ninerouter']);
        $this->assertArrayHasKey('dimension', $configured['embed_profiles']['ninerouter']);
        $this->assertArrayHasKey('budget_tokens_per_modul', $configured);
        $this->assertIsArray($defaults);
    }

    public function test_config_has_no_fallback_driver_key(): void
    {
        $configured = config('ai');

        $this->assertArrayNotHasKey('fallback_driver', $configured);
        $this->assertArrayNotHasKey('fallback_driver', $configured['openai_compatible']);
    }

    public function test_config_defaults_without_env_are_the_documented_values(): void
    {
        // Evaluate config/ai.php with the relevant env vars unset to prove the
        // documented fallbacks (not the test-time overrides) are in place.
        $names = ['AI_BASE_URL', 'AI_CHAT_MODEL', 'AI_EMBED_MODEL', 'AI_EMBED_DIMENSION', 'AI_BUDGET_TOKENS_PER_MODUL', 'AI_CHAT_MODEL_VERSION'];

        foreach ($names as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        $ai = require base_path('config/ai.php');

        $this->assertSame('openai_compatible', $ai['default_driver']);
        $this->assertSame(self::BASE_URL, $ai['openai_compatible']['base_url']);
        $this->assertSame(self::CHAT_MODEL, $ai['openai_compatible']['chat_model']);
        $this->assertSame(self::EMBED_MODEL, $ai['openai_compatible']['embed_model']);
        $this->assertNull($ai['openai_compatible']['chat_model_version']);
        $this->assertSame(90, $ai['openai_compatible']['timeout']);
        $this->assertSame(10000, $ai['max_answer_chars']);
        $this->assertSame(self::EMBED_MODEL, $ai['embed_profiles']['ninerouter']['name']);
        $this->assertSame(self::EMBED_DIMENSION, $ai['embed_profiles']['ninerouter']['dimension']);
        $this->assertSame(500000, $ai['budget_tokens_per_modul']);
    }

    public function test_seeder_creates_one_active_snapshot(): void
    {
        $this->seedAiConfig();

        $this->assertSame(1, AiConfig::query()->count());

        $config = AiConfig::active();

        $this->assertSame(self::CHAT_MODEL, $config->chat_model);
        $this->assertSame('openai_compatible', $config->driver);
        $this->assertSame('ninerouter', $config->embed_driver);
        $this->assertNotNull($config->model_chat_digest);
        $this->assertNotNull($config->embed_model_digest);
        $this->assertSame(64, strlen($config->model_chat_digest));
        $this->assertSame(64, strlen($config->embed_model_digest));
        $this->assertSame(self::EMBED_DIMENSION, $config->embedding_dimension);
        $this->assertNotNull($config->embedding_dimension);
        $this->assertSame(self::EMBED_MODEL, $config->embed_model_name);
        $this->assertSame(self::BASE_URL, $config->base_url);
        $this->assertSame('127.0.0.1', $config->base_url_host);
    }

    public function test_seeder_digests_follow_config(): void
    {
        $this->seedAiConfig();

        $config = AiConfig::active();

        $this->assertSame(
            hash('sha256', self::CHAT_MODEL.'|gemini-flash-2026-09'),
            $config->model_chat_digest
        );
        $this->assertSame(
            hash('sha256', self::EMBED_MODEL.'|'.self::EMBED_DIMENSION),
            $config->embed_model_digest
        );
    }

    public function test_seeder_leaves_feature_inert_but_budgeted(): void
    {
        $this->seedAiConfig();

        $config = AiConfig::active();

        $this->assertFalse($config->allow_external_payload);
        $this->assertFalse($config->ai_enabled);
        $this->assertNotNull($config->budget_tokens_per_modul);
        $this->assertSame(500000, $config->budget_tokens_per_modul);
        $this->assertNull($config->provider_policy_verified_at);

        // The raw row must carry an explicit false, not the column default.
        $row = DB::table('ai_configs')->where('is_active', 1)->first();
        $this->assertSame(0, (int) $row->allow_external_payload);
        $this->assertSame(0, (int) $row->ai_enabled);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seedAiConfig();
        $firstId = AiConfig::active()->getKey();

        $this->seedAiConfig();

        $this->assertSame(1, AiConfig::query()->count(), 'Seeder dua kali tidak boleh menambah baris.');
        $this->assertSame(1, AiConfig::query()->where('is_active', true)->count());
        $this->assertSame($firstId, AiConfig::active()->getKey());
        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'ai.provider.activated')->count(),
            'Aktivasi ulang yang identik tidak boleh menulis audit baru.'
        );
    }

    public function test_seeder_reactivates_when_model_changes(): void
    {
        $this->seedAiConfig();
        $firstId = AiConfig::active()->getKey();

        config(['ai.openai_compatible.chat_model_version' => 'gemini-flash-2026-10']);
        $this->seedAiConfig();

        $active = AiConfig::active();

        $this->assertSame(1, AiConfig::query()->where('is_active', true)->count());
        $this->assertNotSame($firstId, $active->getKey());
        $this->assertSame(self::CHAT_MODEL, $active->chat_model);
        $this->assertSame(
            hash('sha256', self::CHAT_MODEL.'|gemini-flash-2026-10'),
            $active->model_chat_digest
        );
        $this->assertSame(2, AuditLog::query()->where('action', 'ai.provider.activated')->count());
    }

    public function test_seeder_writes_activation_audit_with_host_and_without_secrets(): void
    {
        $this->seedAiConfig();

        $log = AuditLog::query()->where('action', 'ai.provider.activated')->first();

        $this->assertNotNull($log, 'Audit ai.provider.activated harus tercatat.');
        $this->assertIsArray($log->metadata);
        $this->assertSame('127.0.0.1', $log->metadata['host']);
        $this->assertArrayNotHasKey('api_key', $log->metadata);
        $this->assertArrayNotHasKey('authorization', $log->metadata);

        $raw = json_encode($log->metadata);
        $this->assertStringNotContainsString('sk-test-super-secret-token', $raw, 'Metadata audit tidak boleh memuat token.');
        $this->assertStringNotContainsStringIgnoringCase('authorization', $raw);
    }

    public function test_assert_payload_policy_rejects_false(): void
    {
        $this->expectException(ExternalPayloadDisabledException::class);

        $this->configModel(['allow_external_payload' => false])->assertPayloadPolicy();
    }

    public function test_assert_payload_policy_rejects_null(): void
    {
        $this->expectException(ExternalPayloadDisabledException::class);

        $this->configModel(['allow_external_payload' => null])->assertPayloadPolicy();
    }

    public function test_assert_payload_policy_rejects_string_false(): void
    {
        $this->expectException(ExternalPayloadDisabledException::class);

        $model = new AiConfig;
        $model->setRawAttributes(['allow_external_payload' => 'false'], true);

        $model->assertPayloadPolicy();
    }

    public function test_assert_payload_policy_rejects_raw_null_row(): void
    {
        DB::table('ai_configs')->insert([
            'driver' => 'openai_compatible',
            'embed_driver' => 'ninerouter',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $config = AiConfig::query()->latest('id')->firstOrFail();
        $config->allow_external_payload = null;

        $this->expectException(ExternalPayloadDisabledException::class);

        $config->assertPayloadPolicy();
    }

    public function test_assert_payload_policy_allows_true(): void
    {
        $this->expectNotToPerformAssertions();

        $this->configModel(['allow_external_payload' => true])->assertPayloadPolicy();
    }

    public function test_assert_payload_policy_allows_truthy_storage_value(): void
    {
        $this->expectNotToPerformAssertions();

        $model = new AiConfig;
        $model->setRawAttributes(['allow_external_payload' => 1], true);

        $model->assertPayloadPolicy();
    }

    public function test_exception_exposes_error_code(): void
    {
        $this->assertSame('EXTERNAL_PAYLOAD_DISABLED', (new ExternalPayloadDisabledException)->errorCode());
    }

    public function test_seeded_row_passes_payload_policy_only_after_operator_opt_in(): void
    {
        $this->seedAiConfig();

        $config = AiConfig::active();

        try {
            $config->assertPayloadPolicy();
            $this->fail('Seeder default allow_external_payload=false harus ditolak.');
        } catch (ExternalPayloadDisabledException $e) {
            $this->assertSame('EXTERNAL_PAYLOAD_DISABLED', $e->errorCode());
        }

        // Flipping the flag rewrites the snapshot as a new revision; the
        // resulting active row is the one an outbound call would use.
        $config->allow_external_payload = true;
        $config->save();

        $this->assertSame(1, AiConfig::query()->where('is_active', true)->count());
        $this->assertTrue(AiConfig::active()->allow_external_payload);
        AiConfig::active()->assertPayloadPolicy();
    }
}
