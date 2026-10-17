<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records who wrote the current kunci (jawaban standar) on a version item.
 *
 * The dual-control approve flow (T17) refuses an approval by the same assistant
 * that wrote the kunci, so the writer must be persisted on every upsert.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('soal_version_items', function (Blueprint $table) {
            $table->unsignedBigInteger('jawaban_standar_created_by')
                ->nullable()
                ->after('jawaban_standar_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('soal_version_items', function (Blueprint $table) {
            $table->dropColumn('jawaban_standar_created_by');
        });
    }
};
