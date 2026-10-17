<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the ai_evaluations schema described in SPEC §6.
 *
 * The initial migration (2026_10_17_210000) was written before the job and
 * the verdict endpoint existed, so it carried only the columns needed for
 * idempotency. The verdict, the failure detail, the retrieval citation list
 * and the completion timestamp are required by T21 (job), T22 (error codes),
 * T23 (verdict response) and T29 (90 day raw_response retention), so they are
 * added here.
 */
return new class extends Migration
{
    protected string $table = 'ai_evaluations';

    /**
     * @var list<string>
     */
    protected array $columns = [
        'verdict',
        'confidence',
        'alasan',
        'cited_chunk_ids',
        'error_code',
        'error_message',
        'finished_at',
    ];

    public function up(): void
    {
        if (! Schema::hasTable($this->table)) {
            return;
        }

        $missing = array_values(array_filter(
            $this->columns,
            fn (string $column): bool => ! Schema::hasColumn($this->table, $column),
        ));

        if ($missing === []) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) use ($missing) {
            if (in_array('verdict', $missing, true)) {
                // Verdicts are a closed set enforced by the application:
                // sesuai | sebagian_sesuai | tidak_sesuai | tidak_menjawab.
                $table->string('verdict', 30)->nullable()->after('status');
            }

            if (in_array('confidence', $missing, true)) {
                $table->decimal('confidence', 3, 2)->nullable()->after('ai_score');
            }

            if (in_array('alasan', $missing, true)) {
                $table->text('alasan')->nullable()->after('confidence');
            }

            if (in_array('cited_chunk_ids', $missing, true)) {
                $table->json('cited_chunk_ids')->nullable()->after('alasan');
            }

            if (in_array('error_code', $missing, true)) {
                $table->string('error_code', 48)->nullable()->after('raw_response');
            }

            if (in_array('error_message', $missing, true)) {
                $table->text('error_message')->nullable()->after('error_code');
            }

            if (in_array('finished_at', $missing, true)) {
                $table->timestamp('finished_at')->nullable()->after('latency_ms');
            }
        });

        if (in_array('finished_at', $missing, true)) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->index('finished_at', 'ai_evaluations_finished_at_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable($this->table)) {
            return;
        }

        $existing = array_values(array_filter(
            $this->columns,
            fn (string $column): bool => Schema::hasColumn($this->table, $column),
        ));

        if ($existing === []) {
            return;
        }

        if (Schema::hasColumn($this->table, 'finished_at')) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropIndex('ai_evaluations_finished_at_index');
            });
        }

        Schema::table($this->table, function (Blueprint $table) use ($existing) {
            $table->dropColumn($existing);
        });
    }
};
