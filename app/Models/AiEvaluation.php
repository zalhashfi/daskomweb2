<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Canonical AI evaluation for one answer (SPEC 02-01 §6).
 *
 * One row per (jawaban_id, answer_hash, version_set_id, ai_config_id); the
 * unique index makes re-running an evaluation a no-op at persistence level.
 * Rows are only created by the JawabanSubmissionWriter chokepoint — jobs,
 * retries and regrade flows update this row and append to attempts() instead.
 */
class AiEvaluation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_MANUAL_REQUIRED = 'manual_required';

    public const STATUS_KEY_PENDING = 'key_pending';

    /**
     * Statuses after which the evaluation will not run again on its own.
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_DONE,
        self::STATUS_FAILED,
        self::STATUS_MANUAL_REQUIRED,
        self::STATUS_KEY_PENDING,
    ];

    protected $table = 'ai_evaluations';

    protected $fillable = [
        'jawaban_id',
        'jawaban_type',
        'answer_hash',
        'version_set_id',
        'ai_config_id',
        'status',
        'ai_score',
        'max_score',
        'raw_response',
        'driver',
        'response_model',
        'model_drift',
        'tokens_in',
        'tokens_out',
        'latency_ms',
    ];

    protected $hidden = [
        'ai_score',
        'raw_response',
    ];

    protected $casts = [
        'jawaban_id' => 'integer',
        'version_set_id' => 'integer',
        'ai_config_id' => 'integer',
        'ai_score' => 'decimal:2',
        'max_score' => 'integer',
        'model_drift' => 'boolean',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'latency_ms' => 'integer',
    ];

    protected static function booted(): void
    {
        // The unique key must not treat "no version set / no config" as NULL,
        // because MySQL considers NULLs distinct (see the migration).
        static::creating(function (self $evaluation): void {
            foreach (['version_set_id', 'ai_config_id'] as $column) {
                if ($evaluation->getAttribute($column) === null) {
                    $evaluation->setAttribute($column, 0);
                }
            }
        });
    }

    /**
     * Every outbound provider call made for this evaluation.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(AiEvaluationAttempt::class, 'ai_evaluation_id');
    }

    /**
     * Whether the evaluation reached a state that no longer needs work.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
