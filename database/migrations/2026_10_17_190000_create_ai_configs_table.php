<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_configs', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 30)->default('openai_compatible');
            $table->string('embed_driver', 30)->nullable();
            $table->string('base_url', 255)->nullable();
            $table->string('base_url_host', 191)->nullable();
            $table->string('chat_model', 100)->nullable();
            $table->string('model_chat_digest', 64)->nullable();
            $table->string('embed_model_name', 100)->nullable();
            $table->string('embed_model_digest', 64)->nullable();
            $table->unsignedInteger('embedding_dimension')->nullable();
            $table->unsignedInteger('retrieval_top_k')->default(4);
            $table->unsignedInteger('max_context_tokens')->default(4000);
            $table->unsignedInteger('max_answer_chars')->default(10000);
            $table->boolean('allow_external_payload')->default(false);
            $table->boolean('ai_enabled')->default(false);
            $table->unsignedInteger('budget_tokens_per_modul')->default(0);
            $table->timestamp('provider_policy_verified_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_configs');
    }
};
