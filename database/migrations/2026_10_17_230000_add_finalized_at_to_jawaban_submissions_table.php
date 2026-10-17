<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a submission's evaluation set became fully terminal.
 *
 * StatusAggregator::finalize() stamps this column only once every evaluation
 * of the submission has reached a terminal status (done, failed,
 * manual_required, key_pending). NULL means "still in progress" — including
 * submissions that have no evaluations at all.
 */
return new class extends Migration
{
    protected string $table = 'jawaban_submissions';

    public function up(): void
    {
        if (! Schema::hasTable($this->table) || Schema::hasColumn($this->table, 'finalized_at')) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->timestamp('finalized_at')->nullable()->after('locked_by_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable($this->table) || ! Schema::hasColumn($this->table, 'finalized_at')) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->dropColumn('finalized_at');
        });
    }
};
