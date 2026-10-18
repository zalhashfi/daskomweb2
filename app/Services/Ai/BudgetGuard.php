<?php

namespace App\Services\Ai;

use App\Exceptions\BudgetExceededException;
use App\Models\AiBudgetUsage;
use App\Models\AiConfig;

/**
 * Per-modul token budget for one AI config snapshot.
 *
 * `budget_tokens_per_modul` is read from the config row handed in:
 *
 *  - 0 (or less) means UNLIMITED — the modul is never blocked. This matches
 *    the column default, where AI ships disabled, so a missing budget must
 *    not silently block a deliberately provisioned deployment.
 *  - any positive value is a hard cap on `tokens_used` for that modul.
 *
 * Spend lives in `ai_budget_usage`, one row per (modul_id, ai_config_id).
 * A config revision gets a new id, so a raised/lowered cap on a new snapshot
 * starts from zero usage.
 */
class BudgetGuard
{
    /**
     * Throw when the modul has used up (or gone past) its cap.
     *
     * The comparison is `tokens_used >= budget_tokens_per_modul`, so a modul
     * sitting exactly on its limit is already blocked.
     *
     * @throws BudgetExceededException
     */
    public function assertAvailable(int $modulId, AiConfig $config): void
    {
        $budget = $this->budgetFor($config);

        if ($budget <= 0) {
            return;
        }

        $used = $this->tokensUsed($modulId, $config);

        if ($used >= $budget) {
            throw new BudgetExceededException($modulId, $used, $budget);
        }
    }

    /**
     * Add `$tokens` to the modul's spend.
     *
     * The row is created on first use (updateOrInsert), then bumped with a
     * single `tokens_used = tokens_used + ?` UPDATE. The increment happens in
     * SQL, never read-modify-write in PHP, so concurrent records for the same
     * (modul, config) can never overwrite each other and lose tokens.
     */
    public function record(int $modulId, AiConfig $config, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        $configId = (int) $config->getKey();

        // Insert only when the pair is new. The insert leaves tokens_used at
        // its column default (0); it must never overwrite an existing row,
        // because updateOrInsert() would apply the insert values on "update"
        // too and reset the counter.
        AiBudgetUsage::query()->insertOrIgnore([
            'modul_id' => $modulId,
            'ai_config_id' => $configId,
            'tokens_used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AiBudgetUsage::query()
            ->where('modul_id', $modulId)
            ->where('ai_config_id', $configId)
            ->increment('tokens_used', $tokens, ['updated_at' => now()]);
    }

    /**
     * Current spend for the modul under this config snapshot (0 when none).
     */
    public function tokensUsed(int $modulId, AiConfig $config): int
    {
        $used = AiBudgetUsage::query()
            ->where('modul_id', $modulId)
            ->where('ai_config_id', (int) $config->getKey())
            ->value('tokens_used');

        return $used === null ? 0 : (int) $used;
    }

    /**
     * Cap for the modul; 0 or less means unlimited (see class docblock).
     */
    public function budgetFor(AiConfig $config): int
    {
        return (int) ($config->budget_tokens_per_modul ?? 0);
    }
}
