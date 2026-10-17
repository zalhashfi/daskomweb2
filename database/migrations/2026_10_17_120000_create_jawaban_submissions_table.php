<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks one submission "slot" per praktikan, modul, question type and attempt.
 *
 * The optional lock columns record who froze the slot and when, so an attempt
 * can be handed to an assistant (locked_by_type = 'asisten') exactly like the
 * other graded-answer tables in this project.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('jawaban_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('praktikan_id');
            $table->unsignedBigInteger('modul_id');
            $table->string('tipe', 10);
            $table->unsignedInteger('attempt_no')->default(1);
            $table->unsignedBigInteger('jawaban_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_by_type', 20)->nullable();
            $table->unsignedBigInteger('locked_by_id')->nullable();
            $table->timestamps();

            $table->foreign('praktikan_id')
                ->references('id')
                ->on('praktikans')
                ->cascadeOnDelete();
            $table->foreign('modul_id')
                ->references('id')
                ->on('moduls')
                ->cascadeOnDelete();

            $table->unique(['praktikan_id', 'modul_id', 'tipe', 'attempt_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jawaban_submissions');
    }
};
