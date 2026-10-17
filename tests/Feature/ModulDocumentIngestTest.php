<?php

namespace Tests\Feature;

use App\Exceptions\AiDisabledException;
use App\Jobs\IngestModulDocument;
use App\Models\AiConfig;
use App\Models\Modul;
use App\Models\ModulDocument;
use App\Models\ModulDocumentChunk;
use App\Services\Ai\AiClientManager;
use App\Services\Ai\AiGate;
use App\Services\Documents\PdfTextExtractor;
use App\Services\Documents\TextChunker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers App\Jobs\IngestModulDocument (RAG ingest of an approved source PDF).
 *
 * Documents are real (minimal) PDFs written to the faked `local` disk, so
 * extraction is exercised end to end; the provider is faked with Http, so no
 * request leaves the process.
 *
 * Acceptance:
 * - approved document + handle()         → chunks at index_version 1, active
 * - 200 chunks                           → exactly 4 embedding requests
 *                                          (64 / 64 / 64 / 8)
 * - broken PDF                           → failed + reject_reason
 * - draft document                       → no chunks, untouched
 * - vector length ≠ embedding_dimension  → failed + EMBEDDING_DIMENSION_MISMATCH,
 *                                          zero chunks
 */
class ModulDocumentIngestTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://gateway.test';

    private const EMBED_MODEL = 'google/gemini-embedding-001';

    private const EMBEDDING_DIMENSION = 8;

    private AiConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        Auth::guard('web')->logout();

        Storage::fake(IngestModulDocument::DISK);

        config([
            'ai.openai_compatible.base_url' => self::BASE_URL,
            'ai.openai_compatible.api_key' => 'sk-test-secret-ingest',
            'ai.openai_compatible.chat_model' => 'google/gemini-flash',
            'ai.openai_compatible.embed_model' => self::EMBED_MODEL,
        ]);

        $this->config = AiConfig::query()->create([
            'driver' => 'openai_compatible',
            'embed_driver' => AiClientManager::DRIVER_OPENAI_COMPATIBLE,
            'base_url' => self::BASE_URL,
            'chat_model' => 'google/gemini-flash',
            'embed_model_name' => self::EMBED_MODEL,
            'embedding_dimension' => self::EMBEDDING_DIMENSION,
            'retrieval_top_k' => 4,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'allow_external_payload' => true,
            'ai_enabled' => true,
            'budget_tokens_per_modul' => 500000,
            'provider_policy_verified_at' => now(),
            'is_active' => true,
        ]);

        Http::preventStrayRequests();
    }

    // ---------------------------------------------------------------------
    // Acceptance
    // ---------------------------------------------------------------------

    public function test_approved_document_is_chunked_and_activated(): void
    {
        $document = $this->approvedDocument("Modul 1: Pengenalan Linux.\nKoneksi SSH ke server praktikum.");

        $this->fakeEmbeddings(self::EMBEDDING_DIMENSION);
        $this->runJob($document);

        $fresh = $document->fresh();

        $this->assertSame(ModulDocument::STATUS_ACTIVE, $fresh->status);
        $this->assertSame(1, $fresh->index_version);
        $this->assertNull($fresh->reject_reason);

        $chunk = ModulDocumentChunk::query()->sole();

        $this->assertSame($document->id, $chunk->modul_document_id);
        $this->assertSame(1, $chunk->index_version);
        $this->assertSame(0, $chunk->chunk_index);
        $this->assertSame(self::EMBEDDING_DIMENSION, $chunk->embedding_dimension);
        $this->assertCount(self::EMBEDDING_DIMENSION, $chunk->embedding);
        $this->assertStringContainsString('Pengenalan Linux', $chunk->content);
        $this->assertStringContainsString('Koneksi SSH', $chunk->content);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE_URL.'/v1/embeddings'
            && $request['model'] === self::EMBED_MODEL
            && is_array($request['input'])
            && count($request['input']) === 1);
    }

    public function test_two_hundred_chunks_are_embedded_in_four_requests(): void
    {
        $chunker = new TextChunker;
        $text = $this->textWithChunkCount($chunker, 200);

        $document = $this->approvedDocument($text);

        $this->fakeEmbeddings(self::EMBEDDING_DIMENSION);
        $this->runJob($document);

        // 64 / 64 / 64 / 8
        Http::assertSentCount(4);

        $batches = Http::recorded()
            ->map(fn (array $pair): int => count($pair[0]['input']))
            ->values()
            ->all();

        $this->assertSame([64, 64, 64, 8], $batches);
        $this->assertSame(200, ModulDocumentChunk::query()->where('modul_document_id', $document->id)->count());

        $fresh = $document->fresh();

        $this->assertSame(ModulDocument::STATUS_ACTIVE, $fresh->status);
        $this->assertSame(1, $fresh->index_version);
        $this->assertSame(
            range(0, 199),
            ModulDocumentChunk::query()->orderBy('chunk_index')->pluck('chunk_index')->all()
        );
    }

    public function test_broken_pdf_fails_with_reject_reason_and_no_chunks(): void
    {
        $document = $this->documentWithBytes('Bukan PDF sama sekali, hanya teks acak.', 'rusak.pdf');

        $this->fakeEmbeddings(self::EMBEDDING_DIMENSION);
        $this->runJob($document);

        $fresh = $document->fresh();

        $this->assertSame(ModulDocument::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->reject_reason);
        $this->assertStringContainsString(IngestModulDocument::ERROR_PDF_EXTRACTION_FAILED, $fresh->reject_reason);
        $this->assertSame(0, ModulDocumentChunk::query()->count());
        Http::assertNothingSent();
    }

    public function test_truncated_pdf_fails_with_reject_reason(): void
    {
        $document = $this->documentWithBytes("%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\n", 'terpotong.pdf');

        $this->fakeEmbeddings(self::EMBEDDING_DIMENSION);
        $this->runJob($document);

        $fresh = $document->fresh();

        $this->assertSame(ModulDocument::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->reject_reason);
        $this->assertSame(0, ModulDocumentChunk::query()->count());
        Http::assertNothingSent();
    }

    public function test_draft_document_has_no_chunks_and_is_untouched(): void
    {
        $document = $this->approvedDocument('Konten draft yang belum disetujui.');
        $document->forceFill(['status' => ModulDocument::STATUS_DRAFT])->save();

        $this->fakeEmbeddings(self::EMBEDDING_DIMENSION);
        $this->runJob($document);

        $fresh = $document->fresh();

        $this->assertSame(ModulDocument::STATUS_DRAFT, $fresh->status);
        $this->assertSame(0, $fresh->index_version);
        $this->assertNull($fresh->reject_reason);
        $this->assertSame(0, ModulDocumentChunk::query()->count());
        Http::assertNothingSent();
    }

    public function test_vector_with_wrong_length_fails_without_writing_chunks(): void
    {
        $document = $this->approvedDocument('Dokumen dengan dimensi embedding salah.');

        // Off by one: the provider returns 7 values, the config asks for 8.
        $this->fakeEmbeddings(self::EMBEDDING_DIMENSION - 1);
        $this->runJob($document);

        $fresh = $document->fresh();

        $this->assertSame(ModulDocument::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->reject_reason);
        $this->assertStringContainsString(IngestModulDocument::ERROR_EMBEDDING_DIMENSION_MISMATCH, $fresh->reject_reason);
        $this->assertSame(0, ModulDocumentChunk::query()->count());
    }

    public function test_mismatch_in_a_later_batch_still_writes_zero_chunks(): void
    {
        $document = $this->approvedDocument($this->textWithChunkCount(new TextChunker, 200));

        // The first three batches (64 × 3) are fine; the last one (8) is not.
        Http::fake(function (Request $request): array {
            $length = count($request['input']) === 8 ? self::EMBEDDING_DIMENSION - 1 : self::EMBEDDING_DIMENSION;

            return $this->embeddingResponse(count($request['input']), $length);
        });

        $this->runJob($document);

        $fresh = $document->fresh();

        $this->assertSame(ModulDocument::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString(IngestModulDocument::ERROR_EMBEDDING_DIMENSION_MISMATCH, (string) $fresh->reject_reason);
        $this->assertSame(0, ModulDocumentChunk::query()->count());
    }

    // ---------------------------------------------------------------------
    // Gate, re-ingest, provider and file failures
    // ---------------------------------------------------------------------

    public function test_ai_gate_blocks_ingest_before_any_request(): void
    {
        $this->config->forceFill(['ai_enabled' => false])->save();

        $document = $this->approvedDocument('Konten yang tidak boleh keluar.');
        $this->fakeEmbeddings(self::EMBEDDING_DIMENSION);

        $this->runJob($document);

        $fresh = $document->fresh();

        $this->assertSame(ModulDocument::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString(AiDisabledException::ERROR_CODE, (string) $fresh->reject_reason);
        $this->assertSame(0, ModulDocumentChunk::query()->count());
        Http::assertNothingSent();
    }

    public function test_external_payload_policy_blocks_ingest(): void
    {
        $this->config->forceFill(['allow_external_payload' => false])->save();

        $document = $this->approvedDocument('Konten yang tidak boleh keluar.');
        $this->fakeEmbeddings(self::EMBEDDING_DIMENSION);

        $this->runJob($document);

        $fresh = $document->fresh();

        $this->assertSame(ModulDocument::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->reject_reason);
        $this->assertSame(0, ModulDocumentChunk::query()->count());
        Http::assertNothingSent();
    }

    public function test_reingest_bumps_index_version_and_keeps_old_chunks(): void
    {
        $document = $this->approvedDocument('Versi pertama dokumen modul.');

        $this->fakeEmbeddings(self::EMBEDDING_DIMENSION);
        $this->runJob($document);

        $this->assertSame(1, $document->fresh()->index_version);

        // Re-approval of the same document after an edit.
        $document->fresh()->forceFill(['status' => ModulDocument::STATUS_APPROVED])->save();
        $this->runJob($document->fresh());

        $fresh = $document->fresh();

        $this->assertSame(ModulDocument::STATUS_ACTIVE, $fresh->status);
        $this->assertSame(2, $fresh->index_version);
        $this->assertSame(1, ModulDocumentChunk::query()->where('index_version', 1)->count());
        $this->assertSame(1, ModulDocumentChunk::query()->where('index_version', 2)->count());
    }

    public function test_empty_document_fails_without_embedding(): void
    {
        $document = $this->documentWithBytes($this->buildPdf(''), 'kosong.pdf');

        $this->fakeEmbeddings(self::EMBEDDING_DIMENSION);
        $this->runJob($document);

        $fresh = $document->fresh();

        $this->assertSame(ModulDocument::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString(IngestModulDocument::ERROR_EMPTY_DOCUMENT_TEXT, (string) $fresh->reject_reason);
        $this->assertSame(0, ModulDocumentChunk::query()->count());
        Http::assertNothingSent();
    }

    public function test_provider_error_fails_document_and_rethrows_for_retry(): void
    {
        $document = $this->approvedDocument('Konten untuk provider yang sedang down.');

        Http::fake([
            self::BASE_URL.'/v1/embeddings' => Http::response(['error' => 'unavailable'], 503),
        ]);

        try {
            $this->runJob($document);
            $this->fail('Kegagalan provider yang dapat dicoba ulang harus dilempar ulang.');
        } catch (RuntimeException) {
            // Expected: the queue retries; the row is already terminal.
        }

        $fresh = $document->fresh();

        $this->assertSame(ModulDocument::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('EXTERNAL_UNAVAILABLE', (string) $fresh->reject_reason);
        $this->assertSame(0, ModulDocumentChunk::query()->count());
    }

    public function test_missing_file_fails_with_reject_reason(): void
    {
        $document = $this->approvedDocument('Konten.');
        Storage::disk(IngestModulDocument::DISK)->delete($document->path);

        $this->fakeEmbeddings(self::EMBEDDING_DIMENSION);
        $this->runJob($document);

        $fresh = $document->fresh();

        $this->assertSame(ModulDocument::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->reject_reason);
        $this->assertSame(0, ModulDocumentChunk::query()->count());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function runJob(ModulDocument $document): void
    {
        (new IngestModulDocument($document))->handle(
            app(AiClientManager::class),
            app(AiGate::class),
            app(PdfTextExtractor::class),
            app(TextChunker::class),
        );
    }

    /**
     * Fake `/v1/embeddings` returning one deterministic vector per input.
     *
     * The factory must return a Response (not PendingRequest: it is
     * normalised to a promise whose value is then used as the response).
     */
    private function fakeEmbeddings(int $vectorLength): void
    {
        Http::fake(function (Request $request) use ($vectorLength): array {
            return $this->embeddingResponse(count($request['input']), $vectorLength);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function embeddingResponse(int $count, int $vectorLength): array
    {
        $data = [];

        for ($index = 0; $index < $count; $index++) {
            $data[] = [
                'object' => 'embedding',
                'index' => $index,
                'embedding' => array_map(
                    fn (int $position): float => ($index + 1) + ($position / 100),
                    range(0, $vectorLength - 1)
                ),
            ];
        }

        return [
            'object' => 'list',
            'model' => self::EMBED_MODEL,
            'data' => $data,
        ];
    }

    /**
     * An approved document whose stored file is a valid one-page PDF.
     */
    private function approvedDocument(string $text): ModulDocument
    {
        return $this->documentWithBytes($this->buildPdf($text), 'materi.pdf');
    }

    private function documentWithBytes(string $bytes, string $name): ModulDocument
    {
        $path = 'modul-documents/'.uniqid('doc-', true).'-'.$name;
        Storage::disk(IngestModulDocument::DISK)->put($path, $bytes);

        return ModulDocument::create([
            'modul_id' => Modul::factory()->create()->id,
            'original_name' => $name,
            'path' => $path,
            'mime' => str_ends_with($name, '.pdf') ? 'application/pdf' : 'text/plain',
            'size_bytes' => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes),
            'status' => ModulDocument::STATUS_APPROVED,
            'approved_at' => now(),
            'index_version' => 0,
        ]);
    }

    /**
     * A minimal, structurally valid PDF with one page showing `$text`.
     */
    private function buildPdf(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $operations = 'BT /F1 11 Tf 50 750 Td';

        foreach (preg_split('/\n/', $escaped) ?: [''] as $index => $line) {
            if ($index > 0) {
                $operations .= ' 0 -14 Td';
            }

            $operations .= ' ('.$line.') Tj';
        }

        $operations .= ' ET';
        $contentStream = gzcompress($operations);

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /MediaBox [0 0 612 792] /Contents 4 0 R >>',
            4 => '<< /Length '.strlen($contentStream)." /Filter /FlateDecode >>\nstream\n".$contentStream."\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        $pdf = "%PDF-1.4\n";

        foreach ($objects as $number => $body) {
            $pdf .= $number." 0 obj\n".$body."\nendobj\n";
        }

        $size = count($objects) + 1;

        return $pdf.'xref'."\n0 {$size}\n".'trailer << /Size '.$size." /Root 1 0 R >>\nstartxref\n0\n%%EOF\n";
    }

    /**
     * Text the given chunker splits into exactly `$count` chunks: numbered
     * paragraphs of ~1000 characters separated by blank lines, so every
     * paragraph ends its own chunk (each paragraph < chunk size, and the
     * paragraph gap is the strongest boundary).
     */
    private function textWithChunkCount(TextChunker $chunker, int $count): string
    {
        $filler = str_pad('', 1000, 'Materi praktikum sistem operasi linux dasar untuk modul praktikum daskom. ');
        $paragraphs = [];

        for ($number = 1; $number <= $count + 2; $number++) {
            $paragraphs[] = 'Bagian '.str_pad((string) $number, 3, '0', STR_PAD_LEFT).'. '.substr($filler, 0, 1000);

            $text = implode("\n\n", $paragraphs);
            $chunks = count($chunker->chunk($text));

            if ($chunks === $count) {
                return $text;
            }

            if ($chunks > $count) {
                throw new RuntimeException('Jumlah chunk melompati '.$count.' (menjadi '.$chunks.').');
            }
        }

        throw new RuntimeException('Tidak dapat menyusun teks dengan tepat '.$count.' chunk.');
    }
}
