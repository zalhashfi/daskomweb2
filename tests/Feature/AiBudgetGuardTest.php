<?php

namespace Tests\Feature;

use App\Exceptions\BudgetExceededException;
use App\Models\AiBudgetUsage;
use App\Models\AiConfig;
use App\Services\Ai\BudgetGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BudgetGuard + ai_budget_usage (token usage table).
 *
 * Covers the schema (unique modul_id+ai_config_id, default 0), the cap rule
 * (tokens_used >= budget_tokens_per_modul → BUDGET_EXCEEDED), cap 0 =
 * unlimited, cap raised on a new snapshot, per-modul isolation and the
 * atomic increment that concurrent records rely on.
 */
class AiBudgetGuardTest extends TestCase
{
    use RefreshDatabase;

    private BudgetGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new BudgetGuard;
    }

    public function test_table_has_expected_columns_and_unique_key(): void
    {
        $this->assertTrue(Schema::hasTable('ai_budget_usage'));
        $this->assertTrue(Schema::hasColumns('ai_budget_usage', [
            'id',
            'modul_id',
            'ai_config_id',
            'tokens_used',
            'created_at',
            'updated_at',
        ]));

        $indexes = collect(Schema::getIndexes('ai_budget_usage'));

        $unique = $indexes->firstWhere('unique', true);

        $this->assertNotNull($unique, 'Harus ada unique index pada ai_budget_usage.');
        $this->assertSame(['modul_id', 'ai_config_id'], $unique['columns']);
    }

    public function test_tokens_used_defaults_to_zero(): void
    {
        $config = $this->config(500000);

        $usage = AiBudgetUsage::query()->create([
            'modul_id' => 1,
            'ai_config_id' => $config->getKey(),
        ])->fresh();

        $this->assertSame(0, $usage->tokens_used);
    }

    public function test_usage_below_cap_is_available(): void
    {
        $modulId = 10;
        $config = $this->config(500000);

        $this->guard->record($modulId, $config, 400000);

        $this->guard->assertAvailable($modulId, $config);

        $this->assertSame(400000, $this->guard->tokensUsed($modulId, $config));
    }

    public function test_usage_reaching_cap_throws_budget_exceeded(): void
    {
        $modulId = 11;
        $config = $this->config(500000);

        $this->guard->record($modulId, $config, 400000);
        $this->guard->assertAvailable($modulId, $config);

        $this->guard->record($modulId, $config, 120000);

        try {
            $this->guard->assertAvailable($modulId, $config);
            $this->fail('assertAvailable() harus melempar BudgetExceededException.');
        } catch (BudgetExceededException $exception) {
            $this->assertSame('BUDGET_EXCEEDED', $exception->getErrorCode());
            $this->assertSame('BUDGET_EXCEEDED', $exception->errorCode());
            $this->assertSame($modulId, $exception->modulId);
            $this->assertSame(520000, $exception->tokensUsed);
            $this->assertSame(500000, $exception->budgetTokens);
        }
    }

    public function test_usage_exactly_on_cap_is_blocked(): void
    {
        $modulId = 12;
        $config = $this->config(500000);

        $this->guard->record($modulId, $config, 500000);

        $this->expectException(BudgetExceededException::class);

        $this->guard->assertAvailable($modulId, $config);
    }

    public function test_sequential_records_never_lose_tokens(): void
    {
        $modulId = 13;
        $config = $this->config(500000);

        $this->guard->record($modulId, $config, 120000);
        $this->guard->record($modulId, $config, 120000);
        $this->guard->record($modulId, $config, 120000);

        $this->assertSame(360000, $this->guard->tokensUsed($modulId, $config));

        $rows = AiBudgetUsage::query()
            ->where('modul_id', $modulId)
            ->where('ai_config_id', $config->getKey())
            ->get();

        $this->assertCount(1, $rows, 'Setiap (modul, config) hanya boleh punya satu baris.');
        $this->assertSame(360000, $rows->first()->tokens_used);
    }

    public function test_increment_is_atomic_across_a_stale_read(): void
    {
        $modulId = 14;
        $config = $this->config(500000);

        $this->guard->record($modulId, $config, 1000);

        // A stale in-memory copy (as a concurrent writer would hold).
        $stale = AiBudgetUsage::query()->firstOrFail();
        $staleSnapshot = (int) $stale->tokens_used;

        // The other writer commits after the stale copy was read.
        $this->guard->record($modulId, $config, 2500);

        // The stale writer's own increment must land on top, not overwrite.
        AiBudgetUsage::query()
            ->whereKey($stale->getKey())
            ->increment('tokens_used', 4000);

        $this->assertSame(1000, $staleSnapshot);
        $this->assertSame(7500, $this->guard->tokensUsed($modulId, $config));
        $this->assertSame(1000 + 2500 + 4000, (int) $stale->fresh()->tokens_used);
    }

    public function test_raising_cap_on_new_snapshot_allows_again(): void
    {
        $modulId = 15;

        $exhausted = $this->config(500000);
        $this->guard->record($modulId, $exhausted, 520000);

        try {
            $this->guard->assertAvailable($modulId, $exhausted);
            $this->fail('Snapshot lama harus tetap diblokir.');
        } catch (BudgetExceededException) {
            $this->assertSame(520000, $this->guard->tokensUsed($modulId, $exhausted));
        }

        // Raising the cap on an active row creates a new revision (new
        // ai_config_id), which is a fresh budget.
        $previousId = (int) $exhausted->getKey();
        $exhausted->budget_tokens_per_modul = 1000000;
        $exhausted->save();

        $raised = AiConfig::active();

        $this->assertNotSame($previousId, (int) $raised->getKey());
        $this->assertSame(1000000, $raised->budget_tokens_per_modul);
        $this->assertSame(0, $this->guard->tokensUsed($modulId, $raised));

        $this->guard->assertAvailable($modulId, $raised);

        $this->guard->record($modulId, $raised, 300000);
        $this->guard->assertAvailable($modulId, $raised);

        $this->guard->record($modulId, $raised, 750000);

        $this->expectException(BudgetExceededException::class);

        $this->guard->assertAvailable($modulId, $raised);
    }

    public function test_zero_cap_means_unlimited(): void
    {
        $modulId = 16;
        $config = $this->config(0);

        $this->guard->assertAvailable($modulId, $config);

        $this->guard->record($modulId, $config, 10_000_000);

        $this->guard->assertAvailable($modulId, $config);

        $this->assertSame(10_000_000, $this->guard->tokensUsed($modulId, $config));
    }

    public function test_usage_is_isolated_per_modul(): void
    {
        $config = $this->config(500000);

        $this->guard->record(100, $config, 500000);

        $this->expectException(BudgetExceededException::class);

        try {
            $this->guard->assertAvailable(100, $config);
        } finally {
            $this->guard->assertAvailable(101, $config);
        }
    }

    public function test_record_ignores_non_positive_token_counts(): void
    {
        $modulId = 17;
        $config = $this->config(500000);

        $this->guard->record($modulId, $config, 0);
        $this->guard->record($modulId, $config, -50);

        $this->assertSame(0, $this->guard->tokensUsed($modulId, $config));
        $this->assertSame(0, AiBudgetUsage::query()->count());
    }

    /**
     * The single active config snapshot with the given cap, so a later cap
     * change goes through AiConfig's revision mechanism (new ai_config_id).
     */
    private function config(int $budget): AiConfig
    {
        return AiConfig::query()->create([
            'driver' => 'openai_compatible',
            'retrieval_top_k' => 4,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'allow_external_payload' => false,
            'ai_enabled' => false,
            'provider_policy_verified_at' => null,
            'budget_tokens_per_modul' => $budget,
            'is_active' => true,
        ]);
    }
}
