<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores why a whole soal version set was rejected (T15).
 *
 * soal_version_items already had its own reject_reason for kunci rejections;
 * the set-level decision made by approve()/reject() needs its own column.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('soal_version_sets', function (Blueprint $table) {
            $table->text('reject_reason')->nullable()->after('activated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('soal_version_sets', function (Blueprint $table) {
            $table->dropColumn('reject_reason');
        });
    }
};
