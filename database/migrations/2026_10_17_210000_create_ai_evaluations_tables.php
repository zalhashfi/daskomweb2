<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical AI evaluation rows plus one row per outbound provider call.
 *
 * ai_evaluations         : exactly one row per (jawaban_id, answer_hash,
 *                          version_set_id, ai_config_id) — the idempotency key.
 *                          Written only by the chokepoint in
 *                          JawabanSubmissionWriter (T21); jobs and retries never
 *                          insert here.
 * ai_evaluation_attempts : append-only log of every outgoing call belonging to
 *                          one evaluation.
 *
 * NULL-TOLERANT UNIQUE KEY
 * ------------------------
 * MySQL treats NULL as distinct inside a unique index, so a naive
 * UNIQUE (jawaban_id, answer_hash, version_set_id, ai_config_id) would accept
 * two rows when version_set_id / ai_config_id are NULL — precisely the case
 * where the answer was evaluated without a versioned set or an AI config.
 * Both columns therefore store 0 to mean "none". AiEvaluation normalises NULL
 * to 0 on write, so callers may keep passing NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_evaluations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('jawaban_id');
            $table->string('jawaban_type', 10);
            $table->string('answer_hash', 64);
            $table->unsignedBigInteger('version_set_id')->default(0);
            $table->unsignedBigInteger('ai_config_id')->default(0);
            $table->string('status', 20)->default('pending');
            $table->decimal('ai_score', 5, 2)->nullable();
            $table->unsignedInteger('max_score')->nullable();
            $table->longText('raw_response')->nullable();
            $table->string('driver', 30)->nullable();
            $table->string('response_model', 100)->nullable();
            $table->boolean('model_drift')->default(false);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->unique(
                ['jawaban_id', 'answer_hash', 'version_set_id', 'ai_config_id'],
                'ai_evaluations_idempotency_unique'
            );
            $table->index(['jawaban_type', 'jawaban_id']);
            $table->index('status');
        });

        Schema::create('ai_evaluation_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ai_evaluation_id');
            $table->unsignedInteger('attempt_no');
            $table->string('request_hash', 64)->nullable();
            $table->unsignedInteger('http_status')->nullable();
            $table->string('outcome', 30)->nullable();
            $table->string('response_model', 100)->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->timestamps();

            $table->foreign('ai_evaluation_id')
                ->references('id')
                ->on('ai_evaluations')
                ->cascadeOnDelete();

            $table->unique(['ai_evaluation_id', 'attempt_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluation_attempts');
        Schema::dropIfExists('ai_evaluations');
    }
};
