<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records every manual unlock of a jawaban submission performed by a kadiv
 * holding the `unlock-jawaban-submission` permission.
 *
 * The reason is mandatory (enforced by the endpoint, min 15 characters) so
 * every unlock can be traced back to a justification.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('submission_unlocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('submission_id');
            $table->unsignedBigInteger('unlocked_by_asisten_id');
            $table->text('reason');
            $table->timestamps();

            $table->foreign('submission_id')
                ->references('id')
                ->on('jawaban_submissions')
                ->cascadeOnDelete();
            $table->foreign('unlocked_by_asisten_id')
                ->references('id')
                ->on('asistens')
                ->cascadeOnDelete();

            $table->index(['submission_id', 'created_at'], 'submission_unlocks_submission_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submission_unlocks');
    }
};
