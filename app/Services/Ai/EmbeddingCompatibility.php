<?php

namespace App\Services\Ai;

use App\Exceptions\EmbeddingDimensionMismatch;
use App\Models\AiConfig;
use App\Models\ModulDocument;
use App\Models\ModulDocumentChunk;

/**
 * Detects a change of the embedding profile and forces a fresh index_version.
 *
 * The embedding profile is the triple
 * `embed_driver | embed_model_name | embedding_dimension`. No signature is
 * stored anywhere: whenever a chunk is written, its vector length is already
 * persisted in `modul_document_chunks.embedding_dimension`, so the chunks of
 * the highest index_version are compared against the CURRENT config. A
 * different length means the document was indexed with another profile and
 * must be re-embedded.
 *
 * Old chunks are never touched here: `Retriever` keeps reading the highest
 * index_version that exists, so retrieval during a re-index keeps using the
 * previous version until the new one is written.
 */
class EmbeddingCompatibility
{
    /**
     * Stable identity of the embedding profile a config describes.
     *
     * Missing values are normalised so `null` and `''` do not look like a
     * profile switch.
     */
    public static function profileSignature(AiConfig $config): string
    {
        return implode('|', [
            trim((string) ($config->embed_driver ?? '')),
            trim((string) ($config->embed_model_name ?? '')),
            (string) (int) ($config->embedding_dimension ?? 0),
        ]);
    }

    /**
     * Whether the document must be embedded again under `$config`.
     *
     * False when the document carries no embedded chunk yet (nothing to
     * compare against - a first ingest is not a reindex).
     */
    public static function needsReindex(ModulDocument $document, AiConfig $config): bool
    {
        $targetDimension = (int) ($config->embedding_dimension ?? 0);

        if ($targetDimension <= 0) {
            return false;
        }

        $dimensions = self::indexedDimensions($document);

        if ($dimensions === []) {
            return false;
        }

        return array_intersect($dimensions, [$targetDimension]) === [];
    }

    /**
     * Put the document back into `indexing` so the next
     * IngestModulDocument run writes `index_version + 1`. Existing chunks are
     * preserved: they keep carrying their own (older) index_version.
     */
    public static function markIndexing(ModulDocument $document): void
    {
        ModulDocument::query()->whereKey($document->getKey())->update([
            'status' => ModulDocument::STATUS_INDEXING,
            'reject_reason' => null,
            'updated_at' => now(),
        ]);

        $document->refresh();
    }

    /**
     * Guard for any two vectors that are about to be compared or stored
     * together: different lengths have no meaningful similarity, so they are
     * rejected instead of producing a bogus score.
     *
     * @param  array<int|string, mixed>  $a
     * @param  array<int|string, mixed>  $b
     *
     * @throws EmbeddingDimensionMismatch
     */
    public static function assertSameDimension(array $a, array $b): void
    {
        $left = count($a);
        $right = count($b);

        if ($left !== $right) {
            throw new EmbeddingDimensionMismatch(sprintf(
                'Dimensi embedding berbeda: %d vs %d.',
                $left,
                $right,
            ));
        }
    }

    /**
     * Distinct vector lengths carried by the newest chunks of the document
     * (chunks without an embedding are ignored).
     *
     * @return list<int>
     */
    private static function indexedDimensions(ModulDocument $document): array
    {
        $latestVersion = ModulDocumentChunk::query()
            ->where('modul_document_id', $document->getKey())
            ->max('index_version');

        if ($latestVersion === null) {
            return [];
        }

        return ModulDocumentChunk::query()
            ->where('modul_document_id', $document->getKey())
            ->where('index_version', (int) $latestVersion)
            ->whereNotNull('embedding')
            ->pluck('embedding_dimension')
            ->filter(fn ($dimension): bool => $dimension !== null && (int) $dimension > 0)
            ->map(fn ($dimension): int => (int) $dimension)
            ->unique()
            ->values()
            ->all();
    }
}
