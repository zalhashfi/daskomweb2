<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token spend per (modul, AI config snapshot).
 *
 * One row holds the accumulated `tokens_used`; the unique key
 * (modul_id, ai_config_id) lets App\Services\Ai\BudgetGuard::record() use
 * updateOrInsert + an atomic increment without ever inserting a duplicate.
 * A config revision is a new row (new ai_config_id), so its budget starts
 * from zero.
 *
 * Model for this table: AiBudgetUsage (App\Models\AiBudgetUsage).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_budget_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('modul_id');
            $table->unsignedBigInteger('ai_config_id');
            $table->unsignedBigInteger('tokens_used')->default(0);
            $table->timestamps();

            $table->foreign('ai_config_id')
                ->references('id')
                ->on('ai_configs')
                ->cascadeOnDelete();

            $table->unique(['modul_id', 'ai_config_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_budget_usage');
    }
};
