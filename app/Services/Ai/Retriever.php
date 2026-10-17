<?php

namespace App\Services\Ai;

use App\Models\AiConfig;
use App\Models\ModulDocument;
use App\Models\ModulDocumentChunk;
use Illuminate\Support\Collection;

/**
 * Cosine-similarity retrieval over a modul's indexed source documents (RAG).
 *
 * The caller supplies the query as an EMBEDDING VECTOR, not as text:
 * embedding lives in the client layer (T26b) and is not available here, so
 * this class only ranks vectors that already exist.
 *
 * Scope of the search:
 * - only chunks of documents with status `active` in the given modul;
 * - for each such document, only the HIGHEST `index_version` its chunks
 *   actually carry (older versions stay in the table but are never read).
 *
 * Chunks without an embedding, or whose vector length differs from the
 * query's, are skipped (never ranked, never thrown on).
 */
class Retriever
{
    /**
     * Return the `$k` chunks most similar to `$queryVector` for one modul.
     *
     * `$k` defaults to `AiConfig::active()->retrieval_top_k` when null.
     * Ranking is cosine similarity, descending; ties keep the smaller chunk
     * id first so the order is deterministic.
     *
     * A modul without active documents (or an empty query vector) yields an
     * empty list instead of an exception.
     *
     * @param  array<int, int|float>  $queryVector  Embedding of the query text.
     * @return list<array{chunk_id: int, content: string, score: float}>
     */
    public function topK(int $modulId, array $queryVector, ?int $k = null): array
    {
        $k ??= (int) AiConfig::active()->retrieval_top_k;

        if ($k <= 0) {
            return [];
        }

        $query = $this->normalise($queryVector);

        if ($query === null) {
            return [];
        }

        $chunks = $this->chunksForActiveDocuments($modulId);

        if ($chunks->isEmpty()) {
            return [];
        }

        $dimension = count($query);

        return $chunks
            ->map(fn (ModulDocumentChunk $chunk): ?array => $this->score($chunk, $query, $dimension))
            ->filter()
            ->sort(function (array $left, array $right): int {
                return $right['score'] <=> $left['score']
                    ?: $left['chunk_id'] <=> $right['chunk_id'];
            })
            ->take($k)
            ->values()
            ->all();
    }

    /**
     * Chunks of every active document in the modul, restricted to the
     * highest index_version seen for each document.
     *
     * @return Collection<int, ModulDocumentChunk>
     */
    private function chunksForActiveDocuments(int $modulId): Collection
    {
        $documentIds = ModulDocument::query()
            ->where('modul_id', $modulId)
            ->where('status', ModulDocument::STATUS_ACTIVE)
            ->pluck('id');

        if ($documentIds->isEmpty()) {
            return collect();
        }

        $latestByDocument = ModulDocumentChunk::query()
            ->whereIn('modul_document_id', $documentIds)
            ->groupBy('modul_document_id')
            ->selectRaw('modul_document_id, MAX(index_version) as latest_index_version')
            ->pluck('latest_index_version', 'modul_document_id');

        $latestByDocument = $latestByDocument->mapWithKeys(
            fn ($version, $documentId): array => [(int) $documentId => (int) $version]
        );

        $query = ModulDocumentChunk::query()->where(function ($query) use ($latestByDocument): void {
            foreach ($latestByDocument as $documentId => $version) {
                $query->orWhere(function ($query) use ($documentId, $version): void {
                    $query->where('modul_document_id', $documentId)
                        ->where('index_version', $version);
                });
            }
        });

        return $query->orderBy('id')->get();
    }

    /**
     * Score one chunk against the query, or null when it cannot be ranked
     * (missing/invalid embedding or a dimension mismatch).
     *
     * @param  array<int, float>  $query
     * @return array{chunk_id: int, content: string, score: float}|null
     */
    private function score(ModulDocumentChunk $chunk, array $query, int $dimension): ?array
    {
        $embedding = $this->normalise($chunk->embedding ?? []);

        if ($embedding === null || count($embedding) !== $dimension) {
            return null;
        }

        $score = $this->cosine($query, $embedding);

        return [
            'chunk_id' => (int) $chunk->id,
            'content' => (string) $chunk->content,
            'score' => $score,
        ];
    }

    /**
     * Turn a loosely typed vector into a list of floats, or null when it is
     * unusable (empty, non-list keys, or containing non-numeric values).
     *
     * @param  array<int|string, mixed>  $vector
     * @return list<float>|null
     */
    private function normalise(array $vector): ?array
    {
        if ($vector === [] || ! array_is_list($vector)) {
            return null;
        }

        $values = [];

        foreach ($vector as $value) {
            if (! is_int($value) && ! is_float($value)) {
                return null;
            }

            $values[] = (float) $value;
        }

        return $values;
    }

    /**
     * Cosine similarity of two equal-length vectors. A zero-norm vector has
     * no direction, so its similarity is 0.0.
     *
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function cosine(array $left, array $right): float
    {
        $dot = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;

        foreach ($left as $index => $value) {
            $dot += $value * $right[$index];
            $leftNorm += $value * $value;
            $rightNorm += $right[$index] * $right[$index];
        }

        if ($leftNorm <= 0.0 || $rightNorm <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
    }
}
