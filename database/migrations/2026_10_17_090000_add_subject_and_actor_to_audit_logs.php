<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('subject_type', 60)->nullable()->after('description');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            // The default backfills every pre-existing row as an assistant actor.
            $table->string('actor_type', 20)->default('asisten')->after('subject_id');
            $table->unsignedBigInteger('actor_id')->nullable()->after('actor_type');

            $table->index(['subject_type', 'subject_id', 'created_at'], 'audit_logs_subject_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_subject_index');
            $table->dropColumn(['subject_type', 'subject_id', 'actor_type', 'actor_id']);
        });
    }
};
