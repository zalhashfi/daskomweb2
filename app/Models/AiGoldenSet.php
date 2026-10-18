<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One hand-labelled answer of the AI golden set.
 *
 * The row pairs a stored answer (`jawaban_type` + `jawaban_id`, whose
 * `ai_evaluations` row carries the model's `ai_score`) with the assistant's
 * `expected_score`. `ai:golden-eval` compares the two and never writes here.
 */
class AiGoldenSet extends Model
{
    protected $table = 'ai_golden_sets';

    protected $fillable = [
        'modul_id',
        'soal_id',
        'jawaban_id',
        'jawaban_type',
        'expected_score',
        'catatan',
    ];

    protected $casts = [
        'modul_id' => 'integer',
        'soal_id' => 'integer',
        'jawaban_id' => 'integer',
        'expected_score' => 'decimal:2',
    ];
}
