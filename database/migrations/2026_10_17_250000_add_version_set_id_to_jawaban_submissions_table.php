<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T23a — binds every submission attempt to the question version set that was
 * active for its modul at submit time.
 *
 * `version_set_id` is nullable: a modul may be submitted before any set is
 * activated. It is filled by App\Services\Jawaban\SubmissionWriter from the
 * active SoalVersionSet (activated_at not null, newest) and is the anchor the
 * AI chokepoint reads before it creates an `ai_evaluations` row.
 *
 * ON DELETE SET NULL: purging an old version set must not delete the students'
 * submissions; the row simply loses its version anchor (and can no longer
 * produce evaluations).
 */
return new class extends Migration
{
    private const TABLE = 'jawaban_submissions';

    private const FK = 'jawaban_submissions_version_set_id_foreign';

    public function up(): void
    {
        if (Schema::hasColumn(self::TABLE, 'version_set_id')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger('version_set_id')
                ->nullable()
                ->after('attempt_no');

            $table->foreign('version_set_id', self::FK)
                ->references('id')
                ->on('soal_version_sets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'version_set_id')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropForeign(self::FK);
            $table->dropColumn('version_set_id');
        });
    }
};
