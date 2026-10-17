<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\Modul;
use App\Models\ModulDocument;
use App\Models\ModulDocumentChunk;
use App\Services\Ai\Retriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T26 - Retriever::topK(int $modulId, array $queryVector, ?int $k = null).
 *
 * The query is an embedding VECTOR (T26b supplies it); ranking is cosine
 * similarity descending, scoped to active documents and, per document, to
 * the highest index_version present.
 */
class RetrieverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    public function test_relevant_chunk_is_ranked_first(): void
    {
        $modul = Modul::factory()->create();
        $document = $this->makeDocument($modul);

        $similar = $this->makeChunk($document, 1, 0, 'Chunk paling relevan.', [0.90, 0.10, 0.00, 0.00]);
        $partial = $this->makeChunk($document, 1, 1, 'Chunk agak relevan.', [0.50, 0.50, 0.00, 0.00]);
        $orthogonal = $this->makeChunk($document, 1, 2, 'Chunk tidak relevan.', [0.00, 0.00, 1.00, 0.00]);

        $results = (new Retriever)->topK($modul->id, [1.0, 0.0, 0.0, 0.0]);

        $this->assertSame(
            [$similar->id, $partial->id, $orthogonal->id],
            array_column($results, 'chunk_id')
        );
        $this->assertSame('Chunk paling relevan.', $results[0]['content']);
        $this->assertEqualsWithDelta(0.9 / sqrt(0.82), $results[0]['score'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $results[2]['score'], 1e-9);
        $this->assertGreaterThan($results[1]['score'], $results[0]['score']);

        $scores = array_column($results, 'score');
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores, 'Skor harus menurun.');
    }

    public function test_modul_without_active_documents_returns_empty_array(): void
    {
        $modul = Modul::factory()->create();

        // Non-active documents only.
        foreach ([
            ModulDocument::STATUS_DRAFT,
            ModulDocument::STATUS_APPROVED,
            ModulDocument::STATUS_REJECTED,
            ModulDocument::STATUS_INDEXING,
            ModulDocument::STATUS_FAILED,
        ] as $status) {
            $document = $this->makeDocument($modul, ['status' => $status]);
            $this->makeChunk($document, 1, 0, "Chunk {$status}.", [1.0, 0.0]);
        }

        $retriever = new Retriever;

        $this->assertSame([], $retriever->topK($modul->id, [1.0, 0.0]));
        $this->assertSame([], $retriever->topK(999999, [1.0, 0.0]));
    }

    public function test_active_document_without_chunks_returns_empty_array(): void
    {
        $modul = Modul::factory()->create();
        $this->makeDocument($modul);

        $this->assertSame([], (new Retriever)->topK($modul->id, [1.0, 0.0]));
    }

    public function test_k_defaults_to_active_config_retrieval_top_k(): void
    {
        $modul = Modul::factory()->create();
        $document = $this->makeDocument($modul);

        for ($i = 0; $i < 6; $i++) {
            $this->makeChunk($document, 1, $i, "Chunk {$i}.", [1.0, $i / 10]);
        }

        AiConfig::query()->create($this->configAttributes(['retrieval_top_k' => 2]));

        $this->assertCount(2, (new Retriever)->topK($modul->id, [1.0, 0.0]));

        AiConfig::query()->create($this->configAttributes(['retrieval_top_k' => 5]));

        $this->assertCount(5, (new Retriever)->topK($modul->id, [1.0, 0.0]));
    }

    public function test_explicit_k_overrides_the_config_default(): void
    {
        $modul = Modul::factory()->create();
        $document = $this->makeDocument($modul);

        for ($i = 0; $i < 5; $i++) {
            $this->makeChunk($document, 1, $i, "Chunk {$i}.", [1.0, $i / 10]);
        }

        AiConfig::query()->create($this->configAttributes(['retrieval_top_k' => 1]));

        $retriever = new Retriever;

        $this->assertCount(1, $retriever->topK($modul->id, [1.0, 0.0]));
        $this->assertCount(3, $retriever->topK($modul->id, [1.0, 0.0], 3));
        $this->assertCount(5, $retriever->topK($modul->id, [1.0, 0.0], 100));
        $this->assertSame([], $retriever->topK($modul->id, [1.0, 0.0], 0));
    }

    public function test_only_highest_index_version_is_retrieved(): void
    {
        $modul = Modul::factory()->create();
        $document = $this->makeDocument($modul, ['index_version' => 2]);

        $oldChunk = $this->makeChunk($document, 1, 0, 'Chunk versi lama.', [1.0, 0.0, 0.0]);
        $oldChunkTwo = $this->makeChunk($document, 1, 1, 'Chunk versi lama dua.', [1.0, 0.0, 0.0]);
        $newChunk = $this->makeChunk($document, 2, 0, 'Chunk versi baru.', [0.6, 0.8, 0.0]);
        $newChunkTwo = $this->makeChunk($document, 2, 1, 'Chunk versi baru dua.', [0.0, 1.0, 0.0]);

        $results = (new Retriever)->topK($modul->id, [1.0, 0.0, 0.0]);

        $this->assertSame(
            [$newChunk->id, $newChunkTwo->id],
            array_column($results, 'chunk_id')
        );
        $this->assertNotContains($oldChunk->id, array_column($results, 'chunk_id'));
        $this->assertNotContains($oldChunkTwo->id, array_column($results, 'chunk_id'));
    }

    public function test_each_document_uses_its_own_highest_index_version(): void
    {
        $modul = Modul::factory()->create();
        $versionThree = $this->makeDocument($modul, ['index_version' => 3]);
        $versionOne = $this->makeDocument($modul, ['index_version' => 1]);

        $this->makeChunk($versionThree, 2, 0, 'Lama A.', [1.0, 0.0]);
        $latestA = $this->makeChunk($versionThree, 3, 0, 'Baru A.', [1.0, 0.0]);
        $latestB = $this->makeChunk($versionOne, 1, 0, 'Baru B.', [1.0, 0.0]);

        $resultIds = array_column((new Retriever)->topK($modul->id, [1.0, 0.0]), 'chunk_id');

        sort($resultIds);
        $expected = [$latestA->id, $latestB->id];
        sort($expected);

        $this->assertSame($expected, $resultIds);
    }

    public function test_chunks_from_other_moduls_or_documents_are_excluded(): void
    {
        $modul = Modul::factory()->create();
        $otherModul = Modul::factory()->create();

        $document = $this->makeDocument($modul);
        $foreignDocument = $this->makeDocument($otherModul);

        $own = $this->makeChunk($document, 1, 0, 'Chunk modul sendiri.', [1.0, 0.0]);
        $this->makeChunk($foreignDocument, 1, 0, 'Chunk modul lain.', [1.0, 0.0]);

        $results = (new Retriever)->topK($modul->id, [1.0, 0.0]);

        $this->assertSame([$own->id], array_column($results, 'chunk_id'));
    }

    public function test_null_embedding_and_dimension_mismatch_are_skipped(): void
    {
        $modul = Modul::factory()->create();
        $document = $this->makeDocument($modul);

        $this->makeChunk($document, 1, 0, 'Tanpa embedding.', null);
        $this->makeChunk($document, 1, 1, 'Dimensi berbeda (3).', [1.0, 0.0, 0.0]);
        $this->makeChunk($document, 1, 2, 'Dimensi berbeda (1).', [1.0]);
        $matching = $this->makeChunk($document, 1, 3, 'Dimensi cocok.', [0.0, 1.0]);

        $results = (new Retriever)->topK($modul->id, [1.0, 0.0]);

        $this->assertSame([$matching->id], array_column($results, 'chunk_id'));
        $this->assertCount(1, $results);
    }

    public function test_results_have_the_documented_shape(): void
    {
        $modul = Modul::factory()->create();
        $document = $this->makeDocument($modul);
        $chunk = $this->makeChunk($document, 1, 0, 'Isi chunk.', [0.0, 1.0]);

        $results = (new Retriever)->topK($modul->id, [0.0, 1.0]);

        $this->assertCount(1, $results);
        $this->assertSame(['chunk_id', 'content', 'score'], array_keys($results[0]));
        $this->assertSame($chunk->id, $results[0]['chunk_id']);
        $this->assertIsInt($results[0]['chunk_id']);
        $this->assertIsString($results[0]['content']);
        $this->assertSame('Isi chunk.', $results[0]['content']);
        $this->assertIsFloat($results[0]['score']);
        $this->assertEqualsWithDelta(1.0, $results[0]['score'], 1e-9);
    }

    public function test_zero_norm_and_empty_query_vectors_are_handled(): void
    {
        $modul = Modul::factory()->create();
        $document = $this->makeDocument($modul);
        $this->makeChunk($document, 1, 0, 'Chunk.', [1.0, 0.0]);
        $zeroChunk = $this->makeChunk($document, 1, 1, 'Chunk nol.', [0.0, 0.0]);

        $retriever = new Retriever;

        $this->assertSame([], $retriever->topK($modul->id, []));

        $results = $retriever->topK($modul->id, [0.0, 0.0]);
        $zeroScore = collect($results)->firstWhere('chunk_id', $zeroChunk->id);

        $this->assertNotNull($zeroScore);
        $this->assertSame(0.0, $zeroScore['score']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeDocument(Modul $modul, array $attributes = []): ModulDocument
    {
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
     * @param  array<int, int|float>|null  $embedding
     */
    private function makeChunk(ModulDocument $document, int $indexVersion, int $chunkIndex, string $content, ?array $embedding): ModulDocumentChunk
    {
        return ModulDocumentChunk::create([
            'modul_document_id' => $document->id,
            'index_version' => $indexVersion,
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'embedding' => $embedding,
            'embedding_dimension' => $embedding === null ? null : count($embedding),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function configAttributes(array $attributes = []): array
    {
        return array_merge([
            'driver' => 'openai_compatible',
            'embed_driver' => 'ninerouter',
            'embed_model_name' => 'gemini-embedding-001',
            'embedding_dimension' => 2,
            'retrieval_top_k' => 4,
            'allow_external_payload' => false,
            'ai_enabled' => false,
            'is_active' => true,
        ], $attributes);
    }
}
