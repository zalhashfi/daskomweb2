<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cold storage for duplicate answer rows moved out of jawaban_tps and
 * jawaban_mandiris by the `jawaban:archive-duplicates` command.
 *
 * The full original row is kept in `payload` so the move stays reversible
 * without keeping a shadow copy of the source tables. The unique index on
 * (source_table, source_id) is what makes the command idempotent: a row that
 * was already archived can never be inserted twice.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('jawaban_duplicates_archive', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 30);
            $table->unsignedBigInteger('source_id');
            $table->string('tipe', 10);
            $table->unsignedBigInteger('praktikan_id');
            $table->unsignedBigInteger('modul_id');
            $table->unsignedBigInteger('soal_id');
            $table->json('payload');
            $table->string('archived_reason', 50);
            $table->timestamp('archived_at');
            $table->timestamps();

            $table->unique(['source_table', 'source_id'], 'jawaban_duplicates_archive_source_unique');
            $table->index(['praktikan_id', 'modul_id', 'soal_id'], 'jawaban_duplicates_archive_slot_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::drop('jawaban_duplicates_archive');
    }
};
