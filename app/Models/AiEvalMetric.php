<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of AI evaluation metrics (see Migration
 * 2026_10_17_280000_create_ai_eval_metrics_table).
 *
 * `modul_id = NULL` is the global aggregate of `stat_date`; every other row
 * breaks the same day down per modul. Rows are written by
 * App\Services\Ai\MetricsAggregator::aggregate() (command
 * `ai:aggregate-metrics`) and never by the evaluation job itself.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $stat_date
 * @property int|null $modul_id
 * @property int $total
 * @property int $done
 * @property int $failed
 * @property string|null $avg_confidence
 * @property int|null $p50_latency_ms
 * @property int|null $p95_latency_ms
 * @property string|null $mae
 * @property string|null $korelasi
 * @property string|null $agreement
 * @property-read Modul|null $modul
 */
class AiEvalMetric extends Model
{
    protected $table = 'ai_eval_metrics';

    protected $fillable = [
        'stat_date',
        'modul_id',
        'total',
        'done',
        'failed',
        'avg_confidence',
        'p50_latency_ms',
        'p95_latency_ms',
        'mae',
        'korelasi',
        'agreement',
    ];

    protected $casts = [
        'stat_date' => 'date',
        'modul_id' => 'integer',
        'total' => 'integer',
        'done' => 'integer',
        'failed' => 'integer',
        'avg_confidence' => 'decimal:4',
        'p50_latency_ms' => 'integer',
        'p95_latency_ms' => 'integer',
        'mae' => 'decimal:4',
        'korelasi' => 'decimal:4',
        'agreement' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        // The unique index cannot dedupe global rows (MySQL keeps NULLs
        // distinct), so a second global row for the same day is refused here
        // instead of silently creating a parallel "global" series.
        static::saving(function (self $metric): void {
            if ($metric->modul_id !== null) {
                return;
            }

            $duplicate = self::query()
                ->whereDate('stat_date', $metric->stat_date)
                ->whereNull('modul_id')
                ->when($metric->exists, fn ($query) => $query->whereKeyNot($metric->getKey()))
                ->exists();

            if ($duplicate) {
                throw new \RuntimeException(
                    'Metrik global untuk '.$metric->stat_date->toDateString().' sudah ada.'
                );
            }
        });
    }

    public function modul(): BelongsTo
    {
        return $this->belongsTo(Modul::class, 'modul_id');
    }

    public function isGlobal(): bool
    {
        return $this->modul_id === null;
    }
}
