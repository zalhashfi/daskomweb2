<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\AiProviderCredential;
use App\Models\Asisten;
use App\Models\AuditLog;
use App\Services\Ai\ModelCatalog;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Ai\ProviderHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET/POST /api-v1/ai-provider-credentials and its /test probe.
 *
 *  - the key is stored encrypted and never returned in full;
 *  - saving creates a new active row and deactivates the previous one;
 *  - the client factories prefer the stored credential over `.env`;
 *  - a KORDAS without `manage-ai-gate` cannot save or probe;
 *  - the audit row carries only the reason, the host and the last 4 chars;
 *  - the test probe never persists anything.
 */
class AiProviderCredentialTest extends TestCase
{
    use RefreshDatabase;

    private const ENV_BASE_URL = 'https://env-gateway.test';

    private const ENV_API_KEY = 'sk-env-secret-value';

    private const STORED_BASE_URL = 'https://stored-gateway.test';

    private const STORED_API_KEY = 'sk-stored-secret-9876';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutCsrf();

        Auth::guard('web')->logout();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config([
            'ai.openai_compatible.base_url' => self::ENV_BASE_URL,
            'ai.openai_compatible.api_key' => self::ENV_API_KEY,
            'ai.openai_compatible.chat_model' => 'google/gemini-flash',
            'ai.openai_compatible.embed_model' => 'google/gemini-embedding-001',
        ]);

        AiConfig::query()->create([
            'driver' => 'openai_compatible',
            'base_url' => self::ENV_BASE_URL,
            'chat_model' => 'google/gemini-flash',
            'embed_model_name' => 'google/gemini-embedding-001',
            'retrieval_top_k' => 0,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'allow_external_payload' => true,
            'ai_enabled' => false,
            'budget_tokens_per_modul' => 500000,
            'is_active' => true,
        ]);
    }

    public function test_show_reports_the_env_source_when_no_credential_is_stored(): void
    {
        $this->actingAsAssistant('KORDAS', ['view-ai-metrics']);

        $this->getJson('/api-v1/ai-provider-credentials')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.source', 'env')
            ->assertJsonPath('data.base_url', self::ENV_BASE_URL)
            ->assertJsonPath('data.api_key_configured', true)
            ->assertJsonPath('data.api_key_masked', '••••')
            ->assertJsonMissingPath('data.api_key');
    }

    public function test_saving_stores_the_key_encrypted_and_masks_it(): void
    {
        $this->actingAsAssistant('DDC', ['manage-ai-gate', 'view-ai-metrics']);

        $response = $this->postJson('/api-v1/ai-provider-credentials', [
            'base_url' => self::STORED_BASE_URL,
            'api_key' => self::STORED_API_KEY,
            'reason' => 'Ganti gateway ke 9Router produksi',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.source', 'database')
            ->assertJsonPath('data.base_url', self::STORED_BASE_URL)
            ->assertJsonPath('data.api_key_masked', '••••9876');

        $this->assertStringNotContainsString(self::STORED_API_KEY, $response->getContent());

        $row = AiProviderCredential::active();

        $this->assertNotNull($row);
        $this->assertSame('9876', $row->api_key_hint);

        $rawKey = DB::table('ai_provider_credentials')->where('id', $row->getKey())->value('api_key');
        $this->assertNotSame(self::STORED_API_KEY, $rawKey);
        $this->assertSame(self::STORED_API_KEY, $row->api_key);
    }

    public function test_saving_again_deactivates_the_previous_credential(): void
    {
        AiProviderCredential::store(self::STORED_BASE_URL, 'sk-old-secret-1111');

        $this->actingAsAssistant('DDC', ['manage-ai-gate', 'view-ai-metrics']);

        $this->postJson('/api-v1/ai-provider-credentials', [
            'base_url' => 'https://second-gateway.test',
            'api_key' => 'sk-new-secret-2222',
            'reason' => 'Pindah ke gateway kedua',
        ])->assertOk();

        $this->assertSame(1, AiProviderCredential::query()->where('is_active', true)->count());
        $this->assertSame('https://second-gateway.test', AiProviderCredential::active()->base_url);
        $this->assertSame(2, AiProviderCredential::query()->count());
    }

    public function test_audit_row_never_contains_the_full_key(): void
    {
        $assistant = $this->actingAsAssistant('DDC', ['manage-ai-gate']);

        $this->postJson('/api-v1/ai-provider-credentials', [
            'base_url' => self::STORED_BASE_URL,
            'api_key' => self::STORED_API_KEY,
            'reason' => 'Rotasi kunci gateway bulanan',
        ])->assertOk();

        $log = AuditLog::query()->where('action', 'ai.provider.credentials.updated')->first();

        $this->assertNotNull($log);
        $this->assertSame($assistant->getKey(), $log->asisten_id);
        $this->assertSame('stored-gateway.test', $log->metadata['host']);
        $this->assertSame('9876', $log->metadata['api_key_hint']);
        $this->assertStringNotContainsString(self::STORED_API_KEY, (string) json_encode($log->metadata));
    }

    public function test_client_factories_prefer_the_stored_credential(): void
    {
        AiProviderCredential::store(self::STORED_BASE_URL, self::STORED_API_KEY);

        $client = OpenAiCompatibleClient::fromConfig();
        $this->assertSame(self::STORED_BASE_URL, $client->baseUrl);
        $this->assertSame(self::STORED_API_KEY, $client->apiKey);

        $catalog = ModelCatalog::fromConfig();
        $this->assertSame(self::STORED_BASE_URL, $catalog->baseUrl);
        $this->assertSame(self::STORED_API_KEY, $catalog->apiKey);

        $health = ProviderHealth::fromConfig();
        $this->assertSame(self::STORED_BASE_URL, $health->baseUrl);
        $this->assertSame(self::STORED_API_KEY, $health->apiKey);
    }

    public function test_client_factories_fall_back_to_env_without_a_credential(): void
    {
        $client = OpenAiCompatibleClient::fromConfig();

        $this->assertSame(self::ENV_BASE_URL, $client->baseUrl);
        $this->assertSame(self::ENV_API_KEY, $client->apiKey);
    }

    public function test_probe_reports_the_model_count_without_persisting(): void
    {
        Http::fake([
            'probe-gateway.test/v1/models' => Http::response([
                'data' => [
                    ['id' => 'gemini/gemini-flash', 'owned_by' => 'google'],
                    ['id' => 'gemini/gemini-pro', 'owned_by' => 'google'],
                ],
            ], 200),
        ]);

        $this->actingAsAssistant('DDC', ['manage-ai-gate']);

        $this->postJson('/api-v1/ai-provider-credentials/test', [
            'base_url' => 'https://probe-gateway.test',
            'api_key' => 'sk-probe-secret-4444',
            'reason' => 'Uji koneksi sebelum menyimpan',
        ])
            ->assertOk()
            ->assertJsonPath('data.reachable', true)
            ->assertJsonPath('data.models', 2);

        $this->assertSame(0, AiProviderCredential::query()->count());

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-probe-secret-4444'));
    }

    public function test_probe_returns_422_when_the_gateway_rejects_the_key(): void
    {
        Http::fake([
            'probe-gateway.test/v1/models' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $this->actingAsAssistant('DDC', ['manage-ai-gate']);

        $response = $this->postJson('/api-v1/ai-provider-credentials/test', [
            'base_url' => 'https://probe-gateway.test',
            'api_key' => 'sk-probe-secret-4444',
            'reason' => 'Uji koneksi dengan key salah',
        ]);

        $response->assertStatus(422)->assertJsonPath('data.reachable', false);
        $this->assertStringNotContainsString('sk-probe-secret-4444', $response->getContent());
        $this->assertSame(0, AiProviderCredential::query()->count());
    }

    public function test_kordas_without_manage_gate_cannot_save(): void
    {
        $this->actingAsAssistant('KORDAS', ['view-ai-metrics']);

        $this->postJson('/api-v1/ai-provider-credentials', [
            'base_url' => self::STORED_BASE_URL,
            'api_key' => self::STORED_API_KEY,
            'reason' => 'Percobaan tanpa izin',
        ])->assertForbidden();

        $this->assertSame(0, AiProviderCredential::query()->count());
    }

    public function test_validation_rejects_an_invalid_url_and_a_short_reason(): void
    {
        $this->actingAsAssistant('DDC', ['manage-ai-gate']);

        $this->postJson('/api-v1/ai-provider-credentials', [
            'base_url' => 'bukan-url',
            'api_key' => 'short',
            'reason' => 'pendek',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['base_url', 'api_key', 'reason']);
    }

    public function test_a_pasted_v1_url_is_stored_without_the_duplicate_prefix(): void
    {
        Http::fake([
            'probe-gateway.test/v1/models' => Http::response([
                'data' => [['id' => 'gemini/gemini-flash', 'owned_by' => 'google']],
            ], 200),
        ]);

        $this->actingAsAssistant('DDC', ['manage-ai-gate', 'view-ai-metrics']);

        $this->postJson('/api-v1/ai-provider-credentials/test', [
            'base_url' => 'https://probe-gateway.test/v1/',
            'api_key' => 'sk-probe-secret-4444',
            'reason' => 'Uji koneksi dengan URL yang sudah berakhiran v1',
        ])
            ->assertOk()
            ->assertJsonPath('data.models', 1);

        Http::assertSent(fn ($request) => $request->url() === 'https://probe-gateway.test/v1/models');

        $this->postJson('/api-v1/ai-provider-credentials', [
            'base_url' => 'https://probe-gateway.test/v1/',
            'api_key' => 'sk-probe-secret-4444',
            'reason' => 'Simpan URL yang sudah berakhiran v1',
        ])
            ->assertOk()
            ->assertJsonPath('data.base_url', 'https://probe-gateway.test');

        $this->assertSame('https://probe-gateway.test', AiProviderCredential::active()->base_url);
    }

    public function test_a_transport_failure_is_reported_without_a_server_error(): void
    {
        Http::fake([
            'probe-gateway.test/*' => fn (): never => throw new ConnectionException('cURL error 60: SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)'),
        ]);

        $this->actingAsAssistant('DDC', ['manage-ai-gate']);

        $this->postJson('/api-v1/ai-provider-credentials/test', [
            'base_url' => 'https://probe-gateway.test',
            'api_key' => 'sk-probe-secret-4444',
            'reason' => 'Uji koneksi dengan sertifikat tidak valid',
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.reachable', false)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Sertifikat SSL'));
    }

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
}
