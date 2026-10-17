<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned question sets per modul (SPEC 02-01 §6, referenced as "§5.3" by T14).
 *
 * soal_version_sets  : one row per (modul, version_no).
 *                      activated_at IS NULL means the set is drafted but not in force.
 * soal_version_items : snapshot of each question with its bobot/max_score and
 *                      standard-answer (kunci) state.
 *
 * Item model for this table (used by later tasks T15-T17): SoalVersionItem
 * (App\Models\SoalVersionItem), reachable via SoalVersionSet::items().
 *
 * INVARIANT (enforced by the approve flow, not by the DB): SUM(bobot) per
 * (modul, soal_type) on the active version equals 100.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('soal_version_sets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('modul_id');
            $table->unsignedInteger('version_no');
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('created_by_asisten_id')->nullable();
            $table->string('approved_by_type', 20)->nullable();
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->unsignedInteger('bobot_total')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->foreign('modul_id')
                ->references('id')
                ->on('moduls')
                ->cascadeOnDelete();

            $table->unique(['modul_id', 'version_no']);
        });

        Schema::create('soal_version_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('version_set_id');
            $table->string('soal_type', 10);
            $table->unsignedBigInteger('soal_id')->nullable();
            $table->unsignedInteger('bobot')->default(0);
            $table->unsignedInteger('max_score')->default(100);
            $table->text('teks_snapshot')->nullable();
            $table->longText('jawaban_standar')->nullable();
            $table->string('jawaban_standar_status', 20)->default('pending');
            $table->unsignedBigInteger('jawaban_standar_approved_by')->nullable();
            $table->timestamp('jawaban_standar_approved_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();

            $table->foreign('version_set_id')
                ->references('id')
                ->on('soal_version_sets')
                ->cascadeOnDelete();

            $table->unique(['version_set_id', 'soal_type', 'soal_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('soal_version_items');
        Schema::dropIfExists('soal_version_sets');
    }
};
