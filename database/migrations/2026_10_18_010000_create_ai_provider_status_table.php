<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Latest known health of the AI provider, written only by
 * `php artisan ai:health-check` (App\Console\Commands\AiHealthCheck) through
 * App\Services\Ai\ProviderHealth.
 *
 * One row per provider base URL (`id` is the surrogate key): `up` when the
 * last probe succeeded, `down` only after three consecutive failures.
 * `checked_at` is the moment of the last probe, `consecutive_failures` the
 * current streak and `last_error` the reason of the last failure (null after
 * a success).
 *
 * Model for this table: AiProviderStatus (App\Models\AiProviderStatus).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_provider_status', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('up');
            $table->timestamp('checked_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_provider_status');
    }
};
