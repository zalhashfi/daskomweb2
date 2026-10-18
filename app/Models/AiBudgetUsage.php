<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accumulated AI token spend for one (modul, ai_config) pair.
 *
 * Written only through App\Services\Ai\BudgetGuard::record() so the counter
 * is always incremented atomically; reading the budget is done through
 * App\Services\Ai\BudgetGuard::assertAvailable().
 *
 * @property int $id
 * @property int $modul_id
 * @property int $ai_config_id
 * @property int $tokens_used
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read AiConfig|null $config
 */
class AiBudgetUsage extends Model
{
    protected $table = 'ai_budget_usage';

    protected $fillable = [
        'modul_id',
        'ai_config_id',
        'tokens_used',
    ];

    protected $casts = [
        'modul_id' => 'integer',
        'ai_config_id' => 'integer',
        'tokens_used' => 'integer',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(AiConfig::class, 'ai_config_id');
    }
}
