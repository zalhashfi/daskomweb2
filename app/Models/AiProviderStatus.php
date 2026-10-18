<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Latest provider health row (see Migration
 * 2026_10_18_010000_create_ai_provider_status_table).
 *
 * Written only by App\Services\Ai\ProviderHealth (command
 * `ai:health-check`), one row per provider base URL.
 *
 * @property int $id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $checked_at
 * @property int $consecutive_failures
 * @property string|null $last_error
 */
class AiProviderStatus extends Model
{
    public const STATUS_UP = 'up';

    public const STATUS_DOWN = 'down';

    protected $table = 'ai_provider_status';

    protected $fillable = [
        'status',
        'checked_at',
        'consecutive_failures',
        'last_error',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'consecutive_failures' => 'integer',
    ];

    public function isDown(): bool
    {
        return $this->status === self::STATUS_DOWN;
    }
}
