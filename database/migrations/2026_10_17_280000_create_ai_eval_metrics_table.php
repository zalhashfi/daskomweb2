<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily rollup of ai_evaluations (one row per day, optionally per modul).
 *
 * A row with `modul_id = NULL` is the global aggregate of the day; rows with
 * a `modul_id` are the per-modul breakdown. Written only by
 * `php artisan ai:aggregate-metrics` (App\Console\Commands\AiAggregateMetrics)
 * through App\Services\Ai\MetricsAggregator, which recomputes the day from
 * scratch and upserts on (stat_date, modul_id).
 *
 * MySQL treats NULLs as distinct inside a unique index, so a naive
 * UNIQUE (stat_date, modul_id) would accept unlimited global rows. The
 * guarantee therefore lives in two places: AiEvalMetric::booted() resolves the
 * global row with an explicit `WHERE modul_id IS NULL` find, and the writer
 * upserts through an explicit `modul_id IS NULL` predicate.
 *
 * Model for this table: AiEvalMetric (App\Models\AiEvalMetric).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_eval_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('stat_date');
            $table->unsignedBigInteger('modul_id')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->decimal('avg_confidence', 5, 4)->nullable();
            $table->unsignedInteger('p50_latency_ms')->nullable();
            $table->unsignedInteger('p95_latency_ms')->nullable();
            $table->decimal('mae', 8, 4)->nullable();
            $table->decimal('korelasi', 6, 4)->nullable();
            $table->decimal('agreement', 6, 4)->nullable();
            $table->timestamps();

            $table->unique(['stat_date', 'modul_id'], 'ai_eval_metrics_date_modul_unique');
            $table->index('modul_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_eval_metrics');
    }
};
