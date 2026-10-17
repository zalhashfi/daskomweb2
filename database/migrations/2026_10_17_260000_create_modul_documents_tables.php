<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source documents (PDF/DOCX/...) attached to a modul for RAG indexing.
 *
 * modul_documents       : one row per uploaded file, with its storage path,
 *                         mime, size, checksum and approval lifecycle
 *                         (draft|approved|rejected|indexing|active|failed).
 *                         index_version bumps each time the document is
 *                         (re)indexed; chunks carry the same version so old
 *                         chunks can be retired without losing the document.
 * modul_document_chunks : chunked text (+ optional embedding) per
 *                         (modul_document_id, index_version).
 *
 * Model for these tables: ModulDocument (App\Models\ModulDocument).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('modul_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('modul_id');
            $table->unsignedBigInteger('uploaded_by_asisten_id')->nullable();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime', 191);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('reject_reason')->nullable();
            $table->unsignedInteger('index_version')->default(0);
            $table->unsignedBigInteger('approved_by_asisten_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('modul_id')
                ->references('id')
                ->on('moduls')
                ->cascadeOnDelete();

            $table->foreign('uploaded_by_asisten_id')
                ->references('id')
                ->on('asistens')
                ->nullOnDelete();

            $table->foreign('approved_by_asisten_id')
                ->references('id')
                ->on('asistens')
                ->nullOnDelete();

            $table->index(['modul_id', 'status']);
        });

        Schema::create('modul_document_chunks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('modul_document_id');
            $table->unsignedInteger('index_version');
            $table->unsignedInteger('chunk_index');
            $table->mediumText('content');
            $table->json('embedding')->nullable();
            $table->unsignedInteger('embedding_dimension')->nullable();
            $table->timestamps();

            $table->foreign('modul_document_id')
                ->references('id')
                ->on('modul_documents')
                ->cascadeOnDelete();

            $table->index(['modul_document_id', 'index_version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modul_document_chunks');
        Schema::dropIfExists('modul_documents');
    }
};
