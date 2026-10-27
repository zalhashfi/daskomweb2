<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-supplied gateway credentials, stored encrypted (Laravel `encrypted`
 * cast) and never written to the audit log.
 *
 * Exactly one row is active at a time: saving a new URL/key deactivates the
 * previous row and inserts a new one, mirroring the revision rule of
 * `ai_configs` so the history of what was configured is preserved.
 * `api_key_hint` keeps the last four characters only, for the masked display.
 *
 * When no active row exists, the client falls back to
 * `config('ai.openai_compatible.*')` (i.e. the `.env` file).
 *
 * Model for this table: AiProviderCredential (App\Models\AiProviderCredential).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('base_url', 255);
            $table->string('base_url_host', 191)->nullable();
            $table->text('api_key');
            $table->string('api_key_hint', 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_credentials');
    }
};
