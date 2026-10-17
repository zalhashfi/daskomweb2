<?php

namespace Tests\Feature;

use App\Exceptions\AiUnavailableException;
use App\Exceptions\UnknownDriverException;
use App\Models\AiConfig;
use App\Services\Ai\AiClientManager;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T20b — AiClientManager driver resolution + AiUnavailableException.
 *
 * The manager is a pure resolver: chat and embed drivers are read
 * independently, only `openai_compatible` is known (no ollama fallback), and
 * resolving a client never reaches the database.
 */
class AiClientManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    /**
     * Build an unsaved config so nothing is written to ai_configs.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function config(array $attributes = []): AiConfig
    {
        return new AiConfig(array_merge([
            'driver' => 'openai_compatible',
            'embed_driver' => 'openai_compatible',
        ], $attributes));
    }

    public function test_for_chat_returns_openai_compatible_client(): void
    {
        $client = app(AiClientManager::class)->forChat($this->config());

        $this->assertInstanceOf(OpenAiCompatibleClient::class, $client);
        $this->assertInstanceOf(AiClient::class, $client);
    }

    public function test_for_embed_returns_openai_compatible_client(): void
    {
        $client = app(AiClientManager::class)->forEmbed($this->config());

        $this->assertInstanceOf(OpenAiCompatibleClient::class, $client);
        $this->assertInstanceOf(AiClient::class, $client);
    }

    public function test_for_chat_rejects_ollama_driver(): void
    {
        $this->expectException(UnknownDriverException::class);

        app(AiClientManager::class)->forChat($this->config(['driver' => 'ollama']));
    }

    public function test_for_embed_rejects_ollama_driver(): void
    {
        $this->expectException(UnknownDriverException::class);

        app(AiClientManager::class)->forEmbed($this->config(['embed_driver' => 'ollama']));
    }

    public function test_unknown_driver_exception_names_the_column_and_value(): void
    {
        try {
            app(AiClientManager::class)->forEmbed($this->config(['embed_driver' => 'ollama']));
            $this->fail('UnknownDriverException tidak dilempar.');
        } catch (UnknownDriverException $e) {
            $this->assertSame('embed_driver', $e->column);
            $this->assertSame('ollama', $e->driver);
            $this->assertStringContainsString('embed_driver', $e->getMessage());
            $this->assertStringContainsString('ollama', $e->getMessage());
        }
    }

    public function test_empty_driver_is_rejected(): void
    {
        $this->expectException(UnknownDriverException::class);

        app(AiClientManager::class)->forChat($this->config(['driver' => null]));
    }

    public function test_for_chat_ignores_embed_driver(): void
    {
        $client = app(AiClientManager::class)
            ->forChat($this->config(['driver' => 'openai_compatible', 'embed_driver' => 'ollama']));

        $this->assertInstanceOf(OpenAiCompatibleClient::class, $client);
    }

    public function test_for_embed_ignores_chat_driver(): void
    {
        $client = app(AiClientManager::class)
            ->forEmbed($this->config(['driver' => 'ollama', 'embed_driver' => 'openai_compatible']));

        $this->assertInstanceOf(OpenAiCompatibleClient::class, $client);
    }

    public function test_for_chat_reads_only_driver_column(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $config = new AiConfig;
        $config->setRawAttributes(['driver' => 'openai_compatible'], true);

        $client = app(AiClientManager::class)->forChat($config);

        $this->assertInstanceOf(OpenAiCompatibleClient::class, $client);
        $this->assertSame([], DB::getQueryLog(), 'forChat tidak boleh menyentuh database.');
    }

    public function test_ai_client_contract_is_not_bound(): void
    {
        $this->assertFalse(
            app()->bound(AiClient::class),
            'AiClient tidak boleh di-bind ke implementasi konkret mana pun.'
        );
    }

    public function test_manager_is_a_singleton_without_touching_ai_configs(): void
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $first = app()->make(AiClientManager::class);
        $second = app()->make(AiClientManager::class);

        $this->assertSame($first, $second, 'AiClientManager harus singleton.');
        $this->assertSame($first, app(AiClientManager::class));

        $touching = array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains(strtolower($sql), 'ai_configs')
        ));

        $this->assertSame([], $touching, 'Resolusi AiClientManager tidak boleh query ai_configs.');
    }

    public function test_resolving_clients_performs_no_database_query(): void
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $manager = app(AiClientManager::class);
        $manager->forChat($this->config());
        $manager->forEmbed($this->config());

        $this->assertSame([], $queries, 'forChat/forEmbed tidak boleh menjalankan query.');
    }

    public function test_provider_registers_manager_as_singleton(): void
    {
        $this->assertArrayHasKey(AiClientManager::class, app()->getBindings());
        $this->assertTrue(app()->isShared(AiClientManager::class));
        $this->assertFalse(app()->bound(AiClient::class));
    }

    public function test_unavailable_exception_exposes_error_code(): void
    {
        $exception = new AiUnavailableException('EXTERNAL_AUTH', 'kredensial ditolak');

        $this->assertSame('EXTERNAL_AUTH', $exception->errorCode());
        $this->assertSame('EXTERNAL_AUTH', $exception->getErrorCode());
        $this->assertSame('kredensial ditolak', $exception->getMessage());
        $this->assertNull($exception->retryAfter);
    }

    public function test_unavailable_exception_factories_carry_code_and_retry_after(): void
    {
        $rateLimited = AiUnavailableException::rateLimited(17);
        $this->assertSame(AiUnavailableException::CODE_EXTERNAL_RATE_LIMIT, $rateLimited->errorCode());
        $this->assertSame(17, $rateLimited->retryAfter);

        $unavailable = AiUnavailableException::unavailable(5);
        $this->assertSame(AiUnavailableException::CODE_EXTERNAL_UNAVAILABLE, $unavailable->errorCode());
        $this->assertSame(5, $unavailable->retryAfter);

        $notConfigured = AiUnavailableException::notConfigured();
        $this->assertSame(AiUnavailableException::CODE_PROVIDER_NOT_CONFIGURED, $notConfigured->errorCode());
        $this->assertNull($notConfigured->retryAfter);

        $made = AiUnavailableException::make('INVALID_MODEL', 'model salah', null, ['model' => 'x']);
        $this->assertSame('INVALID_MODEL', $made->errorCode());
        $this->assertStringContainsString('model salah', $made->getMessage());
        $this->assertStringContainsString('"model":"x"', $made->getMessage());
    }

    public function test_placeholder_client_reports_not_implemented(): void
    {
        $client = app(AiClientManager::class)->forChat($this->config());

        try {
            $client->chat([['role' => 'user', 'content' => 'halo']]);
            $this->fail('Placeholder client seharusnya melempar AiUnavailableException.');
        } catch (AiUnavailableException $e) {
            $this->assertSame(AiUnavailableException::CODE_DRIVER_NOT_IMPLEMENTED, $e->errorCode());
        }

        $this->expectException(AiUnavailableException::class);

        $client->embed(['halo']);
    }
}
