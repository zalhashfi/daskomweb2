<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-labelled answers used as the ground truth of `ai:golden-eval`.
 *
 * One row fixes both sides of one comparison:
 *  - `jawaban_id` + `jawaban_type` point at the stored answer whose
 *    `ai_evaluations` row holds the `ai_score`;
 *  - `expected_score` is the assistant's hand grade for that answer.
 *
 * `modul_id` / `soal_id` are denormalised so the harness can filter without
 * joining the answer table. A guard-style unique key keeps one label per
 * (answer type, answer id).
 *
 * Written by GoldenSetSeeder (and operators); read only by
 * `php artisan ai:golden-eval`, which never writes to the DB.
 *
 * Model: App\Models\AiGoldenSet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_golden_sets')) {
            return;
        }

        Schema::create('ai_golden_sets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('modul_id')->nullable();
            $table->unsignedBigInteger('soal_id')->nullable();
            $table->unsignedBigInteger('jawaban_id');
            $table->string('jawaban_type', 10);
            $table->decimal('expected_score', 5, 2);
            $table->string('catatan')->nullable();
            $table->timestamps();

            $table->unique(['jawaban_type', 'jawaban_id'], 'ai_golden_sets_answer_unique');
            $table->index('modul_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_golden_sets');
    }
};
