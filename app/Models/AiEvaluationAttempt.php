<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outgoing provider call for an AiEvaluation (append-only audit trail).
 */
class AiEvaluationAttempt extends Model
{
    protected $table = 'ai_evaluation_attempts';

    protected $fillable = [
        'ai_evaluation_id',
        'attempt_no',
        'request_hash',
        'http_status',
        'outcome',
        'response_model',
        'tokens_in',
        'tokens_out',
        'latency_ms',
        'error_code',
    ];

    protected $casts = [
        'ai_evaluation_id' => 'integer',
        'attempt_no' => 'integer',
        'http_status' => 'integer',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'latency_ms' => 'integer',
    ];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(AiEvaluation::class, 'ai_evaluation_id');
    }
}
