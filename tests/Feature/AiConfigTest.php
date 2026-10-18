<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers the ai_configs table (2026_10_17_190000_create_ai_configs_table) and
 * the AiConfig model (T18): default active row resolution, column casts and
 * the immutability rule (a changed digest creates a revision row instead of
 * updating in place; only is_active may be toggled in place).
 *
 * RefreshDatabase runs every migration, so the post-up() shape is the start;
 * down()/up() are re-driven by loading the migration file, mirroring
 * LegacyMigrationRollbackTest.
 */
class AiConfigTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_17_190000_create_ai_configs_table';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    public function test_schema_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('ai_configs'));

        foreach ([
            'id',
            'driver',
            'embed_driver',
            'base_url',
            'base_url_host',
            'chat_model',
            'model_chat_digest',
            'embed_model_name',
            'embed_model_digest',
            'embedding_dimension',
            'retrieval_top_k',
            'max_context_tokens',
            'max_answer_chars',
            'allow_external_payload',
            'ai_enabled',
            'budget_tokens_per_modul',
            'provider_policy_verified_at',
            'is_active',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('ai_configs', $column),
                "Kolom ai_configs.{$column} harus ada."
            );
        }

        $indexes = collect(Schema::getIndexes('ai_configs'));
        $this->assertTrue(
            $indexes->contains(fn (array $index): bool => $index['columns'] === ['is_active']),
            'ai_configs.is_active harus memiliki index.'
        );
    }

    public function test_active_returns_exactly_one_default_row_when_empty(): void
    {
        $this->assertSame(0, AiConfig::query()->count());

        $config = AiConfig::active();

        $this->assertInstanceOf(AiConfig::class, $config);
        $this->assertTrue($config->exists);
        $this->assertSame(4, $config->retrieval_top_k);
        $this->assertSame(4000, $config->max_context_tokens);
        $this->assertSame(10000, $config->max_answer_chars);
        $this->assertSame('openai_compatible', $config->driver);
        $this->assertTrue($config->is_active);
        $this->assertFalse($config->allow_external_payload);
        $this->assertFalse($config->ai_enabled);
    }

    public function test_active_is_idempotent_across_repeated_calls(): void
    {
        $first = AiConfig::active();
        $second = AiConfig::active();
        $third = AiConfig::active();

        $this->assertSame(1, AiConfig::query()->count(), 'active() tidak boleh membuat baris ganda.');
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame($first->getKey(), $third->getKey());
        $this->assertSame(4, $third->retrieval_top_k);
    }

    public function test_active_returns_newest_active_row(): void
    {
        $older = AiConfig::query()->create([
            'model_chat_digest' => str_repeat('a', 64),
            'is_active' => true,
        ]);

        $newer = AiConfig::query()->create([
            'model_chat_digest' => str_repeat('b', 64),
            'is_active' => true,
        ]);

        $this->assertSame($newer->getKey(), AiConfig::active()->getKey());
        $this->assertNotSame($older->getKey(), AiConfig::active()->getKey());
    }

    public function test_casts_are_applied(): void
    {
        $config = AiConfig::query()->create([
            'embedding_dimension' => '1536',
            'retrieval_top_k' => '4',
            'max_context_tokens' => '4000',
            'max_answer_chars' => '10000',
            'allow_external_payload' => 1,
            'ai_enabled' => 0,
            'is_active' => 1,
            'budget_tokens_per_modul' => '1000',
            'provider_policy_verified_at' => '2026-10-17 10:00:00',
        ])->fresh();

        $this->assertSame(1536, $config->embedding_dimension);
        $this->assertSame(4, $config->retrieval_top_k);
        $this->assertSame(4000, $config->max_context_tokens);
        $this->assertSame(10000, $config->max_answer_chars);
        $this->assertTrue($config->allow_external_payload);
        $this->assertFalse($config->ai_enabled);
        $this->assertTrue($config->is_active);
        $this->assertSame(1000, $config->budget_tokens_per_modul);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $config->provider_policy_verified_at);
    }

    public function test_defaults_come_from_migration(): void
    {
        $id = DB::table('ai_configs')->insertGetId([
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('ai_configs')->where('id', $id)->first();

        $this->assertSame('openai_compatible', $row->driver);
        $this->assertSame(4, (int) $row->retrieval_top_k);
        $this->assertSame(4000, (int) $row->max_context_tokens);
        $this->assertSame(10000, (int) $row->max_answer_chars);
        $this->assertSame(0, (int) $row->allow_external_payload);
        $this->assertSame(0, (int) $row->ai_enabled);
        $this->assertSame(0, (int) $row->budget_tokens_per_modul);
        $this->assertSame(1, (int) $row->is_active);
        $this->assertNull($row->provider_policy_verified_at);
    }

    public function test_changing_digest_creates_revision_and_deactivates_old_row(): void
    {
        $config = AiConfig::active();
        $defaultId = $config->getKey();
        $config->model_chat_digest = str_repeat('a', 64);
        $config->save();

        $this->assertSame(str_repeat('a', 64), AiConfig::active()->model_chat_digest);
        $this->assertSame(2, AiConfig::query()->count());
        $this->assertFalse((bool) AiConfig::query()->find($defaultId)->is_active);

        $config = AiConfig::active();
        $before = AiConfig::query()->count();
        $oldId = $config->getKey();

        $config->model_chat_digest = str_repeat('b', 64);
        $config->save();

        $this->assertSame($before + 1, AiConfig::query()->count(), 'Digest baru harus membuat baris revisi.');
        $this->assertFalse(
            (bool) AiConfig::query()->find($oldId)->is_active,
            'Baris lama harus dinonaktifkan.'
        );

        $active = AiConfig::active();
        $this->assertNotSame($oldId, $active->getKey());
        $this->assertSame(str_repeat('b', 64), $active->model_chat_digest);
        $this->assertSame(1, AiConfig::query()->where('is_active', true)->count());
    }

    public function test_changing_other_immutable_field_creates_revision(): void
    {
        $config = AiConfig::active();
        $config->embed_model_digest = str_repeat('c', 64);
        $config->save();
        $oldId = $config->getKey();

        $config = AiConfig::active();
        $config->retrieval_top_k = 8;
        $config->save();

        $this->assertSame(3, AiConfig::query()->count(), 'Default + dua revisi = 3 baris.');
        $this->assertFalse((bool) AiConfig::query()->find($oldId)->is_active);
        $this->assertSame(8, AiConfig::active()->retrieval_top_k);
    }

    public function test_toggling_is_active_updates_in_place(): void
    {
        $config = AiConfig::active();
        $id = $config->getKey();

        $config->is_active = false;
        $config->save();

        $this->assertSame(1, AiConfig::query()->count(), 'Perubahan is_active tidak boleh membuat baris baru.');
        $this->assertFalse((bool) AiConfig::query()->find($id)->is_active);
    }

    public function test_reactivating_deactivated_row_updates_in_place(): void
    {
        $config = AiConfig::active();
        $config->is_active = false;
        $config->save();
        $id = $config->getKey();

        $config->is_active = true;
        $config->save();

        $this->assertSame(1, AiConfig::query()->count());
        $this->assertTrue((bool) AiConfig::query()->find($id)->is_active);
    }

    public function test_active_creates_default_after_every_row_deactivated(): void
    {
        $config = AiConfig::active();
        $config->is_active = false;
        $config->save();

        $fresh = AiConfig::active();

        $this->assertTrue($fresh->is_active);
        $this->assertSame(4, $fresh->retrieval_top_k);
        $this->assertSame(1, AiConfig::query()->where('is_active', true)->count());
    }

    public function test_migration_is_reversible(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Rollback assertions are MySQL specific.');
        }

        $this->assertTrue(Schema::hasTable('ai_configs'), 'up() should create ai_configs.');

        // ai_budget_usage (T28e) and ai_eval_metrics reference ai_configs, so
        // their migrations must be reverted before the drop is allowed.
        $dependent = '2026_10_17_270000_create_ai_budget_usage_table';
        $this->runMigration($dependent, 'down');

        $this->runMigration(self::MIGRATION, 'down');
        $this->assertFalse(Schema::hasTable('ai_configs'), 'down() should drop ai_configs.');

        $this->runMigration(self::MIGRATION, 'up');
        $this->runMigration($dependent, 'up');
        $this->assertTrue(Schema::hasTable('ai_configs'), 'up() should recreate ai_configs.');
        $this->assertTrue(Schema::hasColumn('ai_configs', 'model_chat_digest'));
        $this->assertTrue(Schema::hasColumn('ai_configs', 'is_active'));
    }

    private function runMigration(string $name, string $direction): void
    {
        // Match the exact file stem: the suffix must not be a prefix of
        // another migration timestamp.
        $path = collect(glob(database_path('migrations/*.php')))
            ->first(fn (string $file): bool => basename($file, '.php') === $name);

        $this->assertNotNull($path, "Migration {$name} not found.");

        $migration = require $path;
        $migration->{$direction}();
    }
}
