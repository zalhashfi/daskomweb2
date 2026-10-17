<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T20d — 429/503 are handled with a long `release()` (spec E24 / E32) that
 * must not spend `tries`. The count of those releases lives on the row, so
 * the `MAX_RELEASES` ceiling survives worker restarts and retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_evaluations', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_evaluations', 'release_count')) {
                $table->unsignedInteger('release_count')->default(0)->after('latency_ms');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_evaluations', function (Blueprint $table) {
            if (Schema::hasColumn('ai_evaluations', 'release_count')) {
                $table->dropColumn('release_count');
            }
        });
    }
};
