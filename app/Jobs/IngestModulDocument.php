<?php

namespace App\Jobs;

use App\Exceptions\AiDisabledException;
use App\Exceptions\AiUnavailableException;
use App\Exceptions\EmbeddingDimensionMismatch;
use App\Exceptions\ExternalPayloadDisabledException;
use App\Exceptions\PdfExtractionException;
use App\Exceptions\ProviderPolicyUnverifiedException;
use App\Exceptions\UnknownDriverException;
use App\Models\AiConfig;
use App\Models\ModulDocument;
use App\Models\ModulDocumentChunk;
use App\Services\Ai\AiClientManager;
use App\Services\Ai\AiGate;
use App\Services\Documents\PdfTextExtractor;
use App\Services\Documents\TextChunker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Turns one approved source document into chunks + embeddings (RAG ingest).
 *
 * Lifecycle: `approved` → `indexing` → `active` (index_version + 1), or
 * `failed` with a `reject_reason`. Only documents still `approved` (or
 * retried after `failed`) are processed; any other status is a no-op, so a
 * draft never gains chunks.
 *
 * Order of work (cheapest abort first, provider last):
 *
 *  1. AiGate::assertEnabled($config)          → failed/<CODE>, 0 request
 *  2. read file + extract text                → failed/PDF_EXTRACTION_FAILED
 *  3. chunk text                              → failed/EMPTY_DOCUMENT_TEXT
 *  4. embed in batches of ≤64 texts           → failed/<provider code>
 *  5. verify every vector length against
 *     AiConfig::active()->embedding_dimension → failed/EMBEDDING_DIMENSION_MISMATCH
 *  6. persist chunks + bump index_version     → active
 *
 * Steps 5 and 6 share one transaction: the dimension check happens before
 * anything is written, and chunk rows for the new version are inserted only
 * when every vector has the target length, so a mismatch leaves exactly zero
 * chunks behind.
 */
class IngestModulDocument implements ShouldQueue
{
    use Queueable;

    /**
     * 1 initial run + 2 retries for transient provider outages.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retry 1 and 2.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300];

    /**
     * Worker timeout in seconds; embedding a whole document is slow.
     */
    public int $timeout = 300;

    public const ERROR_PDF_EXTRACTION_FAILED = 'PDF_EXTRACTION_FAILED';

    public const ERROR_EMPTY_DOCUMENT_TEXT = 'EMPTY_DOCUMENT_TEXT';

    public const ERROR_EMBEDDING_DIMENSION_MISMATCH = 'EMBEDDING_DIMENSION_MISMATCH';

    public const ERROR_UNSUPPORTED_FORMAT = 'UNSUPPORTED_FORMAT';

    public const ERROR_INTERNAL = 'INTERNAL_ERROR';

    public const ERROR_DOCUMENT_NOT_FOUND = 'DOCUMENT_NOT_FOUND';

    /**
     * Disk that holds the uploaded source files (ModulDocumentController).
     */
    public const DISK = 'local';

    /**
     * Texts sent per `/v1/embeddings` request.
     */
    public const EMBED_BATCH_SIZE = 64;

    /**
     * Statuses this job accepts.
     */
    public const INGESTABLE_STATUSES = [
        ModulDocument::STATUS_APPROVED,
        ModulDocument::STATUS_FAILED,
    ];

    /**
     * Provider failures worth another run of the whole document.
     */
    public const RETRYABLE_ERROR_CODES = [
        AiUnavailableException::CODE_EXTERNAL_UNAVAILABLE,
        AiUnavailableException::CODE_EXTERNAL_RATE_LIMIT,
        AiUnavailableException::CODE_EXTERNAL_ERROR,
    ];

    public function __construct(public ModulDocument $document) {}

    public function handle(
        AiClientManager $manager,
        AiGate $gate,
        PdfTextExtractor $extractor,
        TextChunker $chunker,
    ): void {
        $document = ModulDocument::query()->find($this->document->getKey());

        if ($document === null) {
            Log::warning('ai.ingest_skipped', [
                'modul_document_id' => $this->document->getKey(),
                'reason' => self::ERROR_DOCUMENT_NOT_FOUND,
            ]);

            return;
        }

        if (! in_array($document->status, self::INGESTABLE_STATUSES, true)) {
            return;
        }

        $config = AiConfig::active();
        $embeddingDimension = (int) $config->embedding_dimension;

        try {
            $gate->assertEnabled($config);
        } catch (Throwable $exception) {
            $this->markFailed($document, $this->errorCodeOf($exception).': '.$exception->getMessage());

            return;
        }

        $nextVersion = (int) $document->index_version + 1;

        $document->forceFill([
            'status' => ModulDocument::STATUS_INDEXING,
            'reject_reason' => null,
        ])->save();

        try {
            $text = $this->extractText($document, $extractor);
            $chunks = $chunker->chunk($text);

            if ($chunks === []) {
                $this->markFailed($document, self::ERROR_EMPTY_DOCUMENT_TEXT.': dokumen tidak memuat teks yang dapat diindeks.', $nextVersion);

                return;
            }

            $client = $manager->forEmbed($config);
            $vectors = [];

            foreach (array_chunk($chunks, self::EMBED_BATCH_SIZE) as $batch) {
                foreach ($client->embed($batch) as $vector) {
                    $vectors[] = $vector;
                }
            }

            if (count($vectors) !== count($chunks)) {
                $this->markFailed(
                    $document,
                    self::ERROR_EMBEDDING_DIMENSION_MISMATCH.': jumlah vektor ('.count($vectors).') tidak sama dengan jumlah chunk ('.count($chunks).').',
                    $nextVersion,
                );

                return;
            }

            $this->persistChunks($document, $nextVersion, $embeddingDimension, $chunks, $vectors);
        } catch (EmbeddingDimensionMismatch $exception) {
            $this->markFailed($document, $exception->getMessage(), $nextVersion);
        } catch (PdfExtractionException $exception) {
            $this->markFailed($document, self::ERROR_PDF_EXTRACTION_FAILED.': '.$exception->getMessage(), $nextVersion);
        } catch (Throwable $exception) {
            $this->fail($document, $nextVersion, $exception);
        }
    }

    /**
     * The last attempt died with an uncaught error: the row must still be
     * terminal instead of staying `indexing` forever.
     */
    public function failed(?Throwable $exception): void
    {
        $document = ModulDocument::query()->find($this->document->getKey());

        if ($document === null || ! in_array($document->status, [ModulDocument::STATUS_INDEXING, ModulDocument::STATUS_APPROVED], true)) {
            return;
        }

        $this->markFailed(
            $document,
            $this->errorCodeOf($exception ?? new RuntimeException).': '.($exception?->getMessage() ?? 'Proses indeksasi gagal.'),
            (int) $document->index_version + 1,
        );
    }

    /**
     * @throws PdfExtractionException
     */
    private function extractText(ModulDocument $document, PdfTextExtractor $extractor): string
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($document->path)) {
            throw new PdfExtractionException('Berkas dokumen tidak ditemukan di penyimpanan.');
        }

        $bytes = (string) $disk->get($document->path);
        $isPdf = strtolower((string) $document->mime) === 'application/pdf'
            || strtolower(pathinfo((string) $document->original_name, PATHINFO_EXTENSION)) === 'pdf'
            || str_contains(substr($bytes, 0, 1024), '%PDF-');

        if (! $isPdf) {
            throw new PdfExtractionException(self::ERROR_UNSUPPORTED_FORMAT.': hanya dokumen PDF yang dapat diindeks saat ini.');
        }

        return $extractor->extract($bytes);
    }

    /**
     * Verify every vector against the configured dimension and write the
     * chunks for `$nextVersion`, then flip the document to `active` — inside
     * one transaction. The check runs first, so a mismatch rolls back with no
     * chunk row written at all.
     *
     * @param  list<string>  $chunks
     * @param  list<list<float>>  $vectors
     *
     * @throws EmbeddingDimensionMismatch when a vector length differs from the target.
     */
    private function persistChunks(
        ModulDocument $document,
        int $nextVersion,
        int $embeddingDimension,
        array $chunks,
        array $vectors,
    ): void {
        DB::transaction(function () use ($document, $nextVersion, $embeddingDimension, $chunks, $vectors): void {
            $mismatch = $this->dimensionMismatch($vectors, $embeddingDimension);

            if ($mismatch !== null) {
                throw new EmbeddingDimensionMismatch($mismatch);
            }

            ModulDocumentChunk::query()
                ->where('modul_document_id', $document->getKey())
                ->where('index_version', $nextVersion)
                ->delete();

            foreach ($chunks as $index => $content) {
                ModulDocumentChunk::query()->create([
                    'modul_document_id' => $document->getKey(),
                    'index_version' => $nextVersion,
                    'chunk_index' => $index,
                    'content' => $content,
                    'embedding' => $vectors[$index],
                    'embedding_dimension' => count($vectors[$index]),
                ]);
            }

            ModulDocument::query()->whereKey($document->getKey())->update([
                'status' => ModulDocument::STATUS_ACTIVE,
                'reject_reason' => null,
                'index_version' => $nextVersion,
                'updated_at' => now(),
            ]);
        });

        $document->refresh();

        Log::info('ai.ingest_done', [
            'modul_document_id' => $document->getKey(),
            'index_version' => $nextVersion,
            'chunks' => count($chunks),
            'embedding_dimension' => $embeddingDimension,
        ]);
    }

    /**
     * A reason string when any vector does not have `$dimension` values, or
     * when there are fewer vectors than chunks; null when everything fits.
     *
     * @param  list<list<float>>  $vectors
     */
    private function dimensionMismatch(array $vectors, int $dimension): ?string
    {
        if ($dimension <= 0) {
            return self::ERROR_EMBEDDING_DIMENSION_MISMATCH.': ai_configs.embedding_dimension belum diatur.';
        }

        foreach ($vectors as $index => $vector) {
            if (count($vector) !== $dimension) {
                return self::ERROR_EMBEDDING_DIMENSION_MISMATCH
                    .': vektor chunk #'.$index.' berukuran '.count($vector)
                    .' dimensi, target '.$dimension.'.';
            }
        }

        return null;
    }

    /**
     * Provider outages are retried by the queue; anything else ends here.
     */
    private function fail(ModulDocument $document, int $nextVersion, Throwable $exception): void
    {
        $errorCode = $this->errorCodeOf($exception);

        $this->markFailed($document, $errorCode.': '.$exception->getMessage(), $nextVersion);

        if (in_array($errorCode, self::RETRYABLE_ERROR_CODES, true)) {
            throw $exception;
        }
    }

    private function markFailed(ModulDocument $document, string $reason, ?int $nextVersion = null): void
    {
        $attributes = [
            'status' => ModulDocument::STATUS_FAILED,
            'reject_reason' => mb_substr($reason, 0, 2000),
        ];

        if ($nextVersion !== null) {
            // The bumped version is recorded even though no chunk carries it,
            // so the next successful ingest starts one step further.
            $attributes['index_version'] = $nextVersion;
        }

        ModulDocument::query()->whereKey($document->getKey())->update($attributes);
        $document->refresh();

        Log::warning('ai.ingest_failed', [
            'modul_document_id' => $document->getKey(),
            'code' => strtok($reason, ':'),
        ]);
    }

    private function errorCodeOf(Throwable $exception): string
    {
        if ($exception instanceof ProviderPolicyUnverifiedException) {
            return ProviderPolicyUnverifiedException::ERROR_CODE;
        }

        if ($exception instanceof AiDisabledException) {
            return AiDisabledException::ERROR_CODE;
        }

        if ($exception instanceof ExternalPayloadDisabledException || $exception instanceof UnknownDriverException) {
            return ExternalPayloadDisabledException::ERROR_CODE;
        }

        if ($exception instanceof PdfExtractionException) {
            return self::ERROR_PDF_EXTRACTION_FAILED;
        }

        if (method_exists($exception, 'errorCode')) {
            $code = $exception->errorCode();

            if (is_string($code) && $code !== '') {
                return $code;
            }
        }

        return self::ERROR_INTERNAL;
    }
}
