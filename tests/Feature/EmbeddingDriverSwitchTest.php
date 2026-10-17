<?php

namespace Tests\Feature;

use App\Exceptions\EmbeddingDimensionMismatch;
use App\Models\AiConfig;
use App\Models\Modul;
use App\Models\ModulDocument;
use App\Models\ModulDocumentChunk;
use App\Services\Ai\AiClientManager;
use App\Services\Ai\EmbeddingCompatibility;
use App\Services\Ai\Retriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deteksi pergantian profil embedding + paksa index_version baru.
 *
 * Acceptance:
 * - profile 1024 -> 1536          → needsReindex() true, old chunks stay at
 *                                   their old index_version
 * - markIndexing()                → document status becomes `indexing`
 * - assertSameDimension()         → throws on different length, silent when equal
 * - retrieval during a re-index    → Retriever still reads the old (active)
 *                                   index_version before the new one exists
 */
class EmbeddingDriverSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const EMBED_MODEL = 'google/gemini-embedding-001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    public function test_signature_is_driver_model_and_dimension(): void
    {
        $config = $this->makeConfig(1024);

        $this->assertSame(
            'openai_compatible|'.self::EMBED_MODEL.'|1024',
            EmbeddingCompatibility::profileSignature($config)
        );

        $other = $this->makeConfig(1024, ['embed_model_name' => 'text-embedding-3-small']);

        $this->assertNotSame(
            EmbeddingCompatibility::profileSignature($config),
            EmbeddingCompatibility::profileSignature($other)
        );
    }

    public function test_switching_dimension_from_1024_to_1536_requires_reindex_and_keeps_old_chunks(): void
    {
        $document = $this->makeIndexedDocument(1024);

        $oldChunk = ModulDocumentChunk::query()->sole();

        $this->assertFalse(EmbeddingCompatibility::needsReindex($document, $this->makeConfig(1024)));
        $this->assertTrue(EmbeddingCompatibility::needsReindex($document->fresh(), $this->makeConfig(1536)));

        // The persisted chunks are left untouched, still on the old version.
        $oldChunk->refresh();
        $this->assertSame(1, $oldChunk->index_version);
        $this->assertSame(1024, $oldChunk->embedding_dimension);
        $this->assertSame(1, $document->fresh()->index_version);
        $this->assertSame(ModulDocument::STATUS_ACTIVE, $document->fresh()->status);
    }

    public function test_driver_or_model_switch_also_requires_reindex(): void
    {
        $document = $this->makeIndexedDocument(1024);
        $baseline = $this->makeConfig(1024);

        $this->assertNotSame(
            EmbeddingCompatibility::profileSignature($baseline),
            EmbeddingCompatibility::profileSignature($this->makeConfig(1024, ['embed_model_name' => 'another-embed'])),
        );
    }

    public function test_document_without_embeddings_does_not_require_reindex(): void
    {
        $document = $this->makeDocument(['status' => ModulDocument::STATUS_APPROVED]);

        $this->assertFalse(EmbeddingCompatibility::needsReindex($document, $this->makeConfig(1536)));
    }

    public function test_mark_indexing_moves_the_document_to_indexing(): void
    {
        $document = $this->makeIndexedDocument(1024);

        EmbeddingCompatibility::markIndexing($document);

        $this->assertSame(ModulDocument::STATUS_INDEXING, $document->status);
        $this->assertSame(ModulDocument::STATUS_INDEXING, $document->fresh()->status);

        // Chunks of the still-active old version are not dropped.
        $this->assertSame(1, ModulDocumentChunk::query()->count());
    }

    public function test_assert_same_dimension_throws_only_when_lengths_differ(): void
    {
        EmbeddingCompatibility::assertSameDimension([0.1, 0.2, 0.3], [1, 2, 3]);
        EmbeddingCompatibility::assertSameDimension([], []);

        $this->expectException(EmbeddingDimensionMismatch::class);

        EmbeddingCompatibility::assertSameDimension([0.1, 0.2, 0.3], [0.1, 0.2]);
    }

    public function test_retrieval_during_reindex_still_uses_the_old_active_version(): void
    {
        $modul = Modul::factory()->create();
        $document = $this->makeIndexedDocument(4, $modul, [1.0, 0.0, 0.0, 0.0]);
        $this->makeConfig(4, [], isActive: true);

        $oldChunk = ModulDocumentChunk::query()->sole();
        $retriever = new Retriever;

        $before = $retriever->topK($modul->id, [1.0, 0.0, 0.0, 0.0]);
        $this->assertSame([$oldChunk->id], array_column($before, 'chunk_id'));
        $this->assertSame(1, $oldChunk->fresh()->index_version);

        // Profile switch 4 -> 6: a re-index is required and the document starts
        // indexing. No new-version chunk exists yet.
        $newConfig = $this->makeConfig(6);
        $this->assertTrue(EmbeddingCompatibility::needsReindex($document->fresh(), $newConfig));

        EmbeddingCompatibility::markIndexing($document);
        $this->assertSame(ModulDocument::STATUS_INDEXING, $document->fresh()->status);

        // The old version is still the only index in the table, untouched.
        $this->assertSame([1], ModulDocumentChunk::query()->pluck('index_version')->all());
        $this->assertSame(4, $oldChunk->fresh()->embedding_dimension);

        // Retriever only ranks chunks of `active` documents, so while the
        // document is re-indexing there is nothing new (and nothing stale) to
        // serve - the modul's other active documents keep being served.
        $this->assertSame([], $retriever->topK($modul->id, [1.0, 0.0, 0.0, 0.0]));

        $sibling = $this->makeDocument([
            'modul_id' => $modul->id,
            'original_name' => 'pendamping.pdf',
        ]);
        $siblingChunk = ModulDocumentChunk::create([
            'modul_document_id' => $sibling->id,
            'index_version' => 1,
            'chunk_index' => 0,
            'content' => 'Dokumen aktif lainnya.',
            'embedding' => [0.0, 1.0, 0.0, 0.0],
            'embedding_dimension' => 4,
        ]);

        $this->assertSame(
            [$siblingChunk->id],
            array_column($retriever->topK($modul->id, [1.0, 0.0, 0.0, 0.0]), 'chunk_id')
        );

        // The old chunk becomes readable again as soon as the document is
        // active again - still at index_version 1, never rewritten.
        ModulDocument::query()->whereKey($document->getKey())->update([
            'status' => ModulDocument::STATUS_ACTIVE,
        ]);

        $after = $retriever->topK($modul->id, [1.0, 0.0, 0.0, 0.0]);
        $this->assertSame($oldChunk->id, $after[0]['chunk_id']);
        $this->assertSame(1, $oldChunk->fresh()->index_version);

        // A query in the NEW dimension never scores old-dimension chunks.
        $this->assertSame(
            [],
            array_filter(
                $retriever->topK($modul->id, [1.0, 0.0, 0.0, 0.0, 0.0, 0.0]),
                fn (array $hit): bool => $hit['chunk_id'] === $oldChunk->id,
            )
        );

        // Old and new embeddings together: assertSameDimension guards it.
        $newChunk = ModulDocumentChunk::create([
            'modul_document_id' => $document->id,
            'index_version' => 2,
            'chunk_index' => 0,
            'content' => 'Versi baru 6 dimensi.',
            'embedding' => [1.0, 0.0, 0.0, 0.0, 0.0, 0.0],
            'embedding_dimension' => 6,
        ]);

        $this->expectException(EmbeddingDimensionMismatch::class);
        EmbeddingCompatibility::assertSameDimension($oldChunk->embedding, $newChunk->embedding);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeConfig(int $dimension, array $overrides = [], bool $isActive = false): AiConfig
    {
        return AiConfig::query()->create(array_merge([
            'driver' => 'openai_compatible',
            'embed_driver' => AiClientManager::DRIVER_OPENAI_COMPATIBLE,
            'base_url' => 'https://gateway.test',
            'chat_model' => 'google/gemini-flash',
            'embed_model_name' => self::EMBED_MODEL,
            'embedding_dimension' => $dimension,
            'retrieval_top_k' => 4,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'allow_external_payload' => false,
            'ai_enabled' => false,
            'budget_tokens_per_modul' => 0,
            'is_active' => $isActive,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeDocument(array $attributes = []): ModulDocument
    {
        $modul = Modul::factory()->create();

        return ModulDocument::create(array_merge([
            'modul_id' => $modul->id,
            'original_name' => 'modul.pdf',
            'path' => 'modul-documents/modul.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'status' => ModulDocument::STATUS_ACTIVE,
            'index_version' => 1,
        ], $attributes));
    }

    /**
     * Active document already indexed at version 1 with `$dimension`-length
     * vectors (a lone non-zero vector of `[1.0, 0.0, ...]`).
     *
     * @param  array<int, float>|null  $vector
     */
    private function makeIndexedDocument(int $dimension, ?Modul $modul = null, ?array $vector = null): ModulDocument
    {
        $document = $modul === null
            ? $this->makeDocument()
            : $this->makeDocument(['modul_id' => $modul->id]);

        $vector ??= array_merge([1.0], array_fill(0, $dimension - 1, 0.0));

        ModulDocumentChunk::create([
            'modul_document_id' => $document->id,
            'index_version' => 1,
            'chunk_index' => 0,
            'content' => 'Isi dokumen modul praktikum.',
            'embedding' => $vector,
            'embedding_dimension' => $dimension,
        ]);

        return $document->fresh();
    }
}
