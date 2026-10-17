<?php

namespace Tests\Feature;

use App\Exceptions\AiUnavailableException;
use App\Exceptions\ModelCatalogException;
use App\Services\Ai\ModelCatalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T20e — model validation against `GET /v1/models` (spec §5.4.0a, D21/E29).
 *
 * Covers MODEL_NOT_FOUND (message carries the id), COMBO_NOT_ALLOWED,
 * the 24h catalogue cache (one request per TTL window), the `ai:validate-model`
 * exit codes, and that /v1/models is the only endpoint touched.
 */
class AiModelCatalogTest extends TestCase
{
    private const BASE_URL = 'https://gateway.test';

    private const API_KEY = 'sk-test-secret-5f3a9c';

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

    /**
     * @param  list<array<string, mixed>>  $data
     */
    private function fakeCatalog(array $data): void
    {
        Http::fake([
            $this->modelsUrl() => Http::response(['object' => 'list', 'data' => $data], 200),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function model(string $id, ?string $ownedBy = 'provider'): array
    {
        $entry = ['id' => $id, 'object' => 'model', 'created' => 1700000000];

        if ($ownedBy !== null) {
            $entry['owned_by'] = $ownedBy;
        }

        return $entry;
    }

    private function catalog(): ModelCatalog
    {
        return ModelCatalog::fromConfig();
    }

    private function assertCatalogError(string $code, string $id, callable $call): ModelCatalogException
    {
        try {
            $call();
            $this->fail('ModelCatalogException '.$code.' tidak dilempar untuk '.$id.'.');
        } catch (ModelCatalogException $e) {
            $this->assertSame($code, $e->errorCode());

            return $e;
        }
    }

    public function test_existing_model_passes_with_single_request_to_v1_models(): void
    {
        $this->fakeCatalog([$this->model('openai/gpt-4o'), $this->model(self::CHAT_MODEL)]);

        $this->catalog()->assertChatModel(self::CHAT_MODEL);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === $this->modelsUrl()
            && $request->method() === 'GET'
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY));
        Http::assertNotSent(fn (Request $request): bool => $request->url() !== $this->modelsUrl());
    }

    public function test_missing_model_throws_model_not_found_carrying_the_id(): void
    {
        $this->fakeCatalog([$this->model('openai/gpt-4o')]);

        $error = $this->assertCatalogError(
            ModelCatalogException::ERROR_CODE_MODEL_NOT_FOUND,
            'google/tidak-ada',
            fn () => $this->catalog()->assertChatModel('google/tidak-ada'),
        );

        $this->assertSame('MODEL_NOT_FOUND', $error->errorCode());
        $this->assertSame('MODEL_NOT_FOUND', $error->getErrorCode());
        $this->assertStringContainsString('google/tidak-ada', $error->getMessage());
    }

    public function test_combo_model_throws_combo_not_allowed(): void
    {
        $this->fakeCatalog([$this->model('combo/smart', 'combo')]);

        $error = $this->assertCatalogError(
            ModelCatalogException::ERROR_CODE_COMBO_NOT_ALLOWED,
            'combo/smart',
            fn () => $this->catalog()->assertChatModel('combo/smart'),
        );

        $this->assertSame('COMBO_NOT_ALLOWED', $error->errorCode());
        $this->assertStringContainsString('combo/smart', $error->getMessage());
    }

    public function test_owned_by_combo_only_rejects_the_matching_entry(): void
    {
        $this->fakeCatalog([
            $this->model('combo/fast', 'combo'),
            $this->model(self::CHAT_MODEL, 'google'),
        ]);

        $this->catalog()->assertChatModel(self::CHAT_MODEL);

        $this->assertCatalogError(
            ModelCatalogException::ERROR_CODE_COMBO_NOT_ALLOWED,
            'combo/fast',
            fn () => $this->catalog()->assertChatModel('combo/fast'),
        );
    }

    public function test_catalog_tolerates_entries_without_string_id(): void
    {
        $this->fakeCatalog([['object' => 'model'], ['id' => 'acme/tanpa-owner', 'object' => 'model']]);

        $this->catalog()->assertChatModel('acme/tanpa-owner');

        Http::assertSentCount(1);
    }

    public function test_second_call_within_ttl_causes_no_new_request(): void
    {
        $this->fakeCatalog([$this->model(self::CHAT_MODEL)]);

        $catalog = $this->catalog();
        $catalog->assertChatModel(self::CHAT_MODEL);
        $catalog->assertChatModel(self::CHAT_MODEL);

        $this->assertCatalogError(
            ModelCatalogException::ERROR_CODE_MODEL_NOT_FOUND,
            'google/hilang',
            fn () => (new ModelCatalog(self::BASE_URL, self::API_KEY))->assertChatModel('google/hilang'),
        );

        Http::assertSentCount(1);
    }

    public function test_cache_expires_after_24_hours(): void
    {
        $this->fakeCatalog([$this->model(self::CHAT_MODEL)]);

        $this->catalog()->assertChatModel(self::CHAT_MODEL);

        $this->travel(25)->hours();

        $this->catalog()->assertChatModel(self::CHAT_MODEL);

        Http::assertSentCount(2);
    }

    public function test_unreachable_catalogue_maps_status_to_ai_unavailable(): void
    {
        Http::fake([$this->modelsUrl() => Http::response(['error' => 'unauthorized'], 401)]);

        try {
            $this->catalog()->assertChatModel(self::CHAT_MODEL);
            $this->fail('AiUnavailableException tidak dilempar.');
        } catch (AiUnavailableException $e) {
            $this->assertSame(AiUnavailableException::CODE_EXTERNAL_AUTH, $e->errorCode());
        }
    }

    public function test_command_exits_zero_when_model_exists(): void
    {
        $this->fakeCatalog([$this->model(self::CHAT_MODEL)]);

        $exitCode = Artisan::call('ai:validate-model', ['model' => self::CHAT_MODEL]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString(self::CHAT_MODEL, $output);
        Http::assertSentCount(1);
    }

    public function test_command_defaults_to_configured_chat_model_and_exits_zero(): void
    {
        $this->fakeCatalog([$this->model(self::CHAT_MODEL)]);

        $this->assertSame(0, Artisan::call('ai:validate-model'));
        Http::assertSentCount(1);
    }

    public function test_command_exits_one_when_model_missing(): void
    {
        $this->fakeCatalog([$this->model('openai/gpt-4o')]);

        $exitCode = Artisan::call('ai:validate-model', ['model' => 'google/hilang']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('google/hilang', $output);
        $this->assertStringContainsString('tidak ditemukan', $output);
    }

    public function test_command_exits_one_for_combo_model(): void
    {
        $this->fakeCatalog([$this->model('combo/smart', 'combo')]);

        $exitCode = Artisan::call('ai:validate-model', ['model' => 'combo/smart']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Combo', $output);
    }

    public function test_command_exits_one_when_catalogue_unreachable(): void
    {
        Http::fake([$this->modelsUrl() => Http::response(['error' => 'boom'], 503)]);

        $exitCode = Artisan::call('ai:validate-model', ['model' => self::CHAT_MODEL]);

        $this->assertSame(1, $exitCode);
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('ai:validate-model', Artisan::all());
    }
}
