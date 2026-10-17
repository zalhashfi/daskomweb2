<?php

namespace Tests\Feature;

use App\Models\Asisten;
use App\Models\Modul;
use App\Models\ModulDocument;
use App\Models\ModulDocumentChunk;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers the modul_documents / modul_document_chunks schema
 * (2026_10_17_260000_create_modul_documents_tables) and the ModulDocument
 * model (fillable, casts, modul()/uploader() relations, default status).
 *
 * RefreshDatabase runs every migration, so the post-up() shape is the start;
 * down()/up() are re-driven by loading the migration file, mirroring
 * LegacyMigrationRollbackTest.
 */
class ModulDocumentSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_17_260000_create_modul_documents_tables';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    public function test_schema_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('modul_documents'));
        $this->assertTrue(Schema::hasTable('modul_document_chunks'));

        foreach ([
            'id',
            'modul_id',
            'uploaded_by_asisten_id',
            'original_name',
            'path',
            'mime',
            'size_bytes',
            'checksum_sha256',
            'status',
            'reject_reason',
            'index_version',
            'approved_by_asisten_id',
            'approved_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('modul_documents', $column),
                "Kolom modul_documents.{$column} harus ada."
            );
        }

        foreach ([
            'id',
            'modul_document_id',
            'index_version',
            'chunk_index',
            'content',
            'embedding',
            'embedding_dimension',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('modul_document_chunks', $column),
                "Kolom modul_document_chunks.{$column} harus ada."
            );
        }
    }

    public function test_document_is_created_with_draft_status_by_default(): void
    {
        $modul = Modul::factory()->create();

        $document = ModulDocument::create([
            'modul_id' => $modul->id,
            'original_name' => 'modul-1.pdf',
            'path' => 'modul-documents/modul-1.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 204800,
            'checksum_sha256' => str_repeat('a', 64),
        ]);

        $stored = $document->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(ModulDocument::STATUS_DRAFT, $stored->status);
        $this->assertDatabaseHas('modul_documents', [
            'id' => $document->id,
            'modul_id' => $modul->id,
            'status' => 'draft',
        ]);
    }

    public function test_casts_are_applied(): void
    {
        $document = $this->makeDocument([
            'size_bytes' => 1234,
            'index_version' => 3,
            'approved_at' => '2026-10-17 10:00:00',
        ]);

        $this->assertSame(1234, $document->size_bytes);
        $this->assertSame(3, $document->index_version);
        $this->assertInstanceOf(\Carbon\Carbon::class, $document->approved_at);
        $this->assertSame('2026-10-17 10:00:00', $document->approved_at->format('Y-m-d H:i:s'));
    }

    public function test_default_index_version_is_zero(): void
    {
        $document = $this->makeDocument();

        $this->assertSame(0, $document->index_version);
        $this->assertNull($document->approved_at);
        $this->assertNull($document->reject_reason);
    }

    public function test_modul_relation_returns_the_owning_modul(): void
    {
        $modul = Modul::factory()->create();
        $other = Modul::factory()->create();
        $document = $this->makeDocument(['modul_id' => $modul->id])->fresh();

        $this->assertInstanceOf(Modul::class, $document->modul);
        $this->assertSame($modul->id, $document->modul->id);
        $this->assertNotSame($other->id, $document->modul->id);
    }

    public function test_uploader_relation_returns_the_uploading_assistant(): void
    {
        $assistant = $this->makeAssistant();
        $document = $this->makeDocument(['uploaded_by_asisten_id' => $assistant->id])->fresh();

        $this->assertInstanceOf(Asisten::class, $document->uploader);
        $this->assertSame($assistant->id, $document->uploader->id);
    }

    public function test_uploader_is_null_when_not_provided(): void
    {
        $document = $this->makeDocument(['uploaded_by_asisten_id' => null])->fresh();

        $this->assertNull($document->uploaded_by_asisten_id);
        $this->assertNull($document->uploader);
    }

    public function test_chunks_are_stored_with_embedding_json(): void
    {
        $document = $this->makeDocument();

        $chunk = ModulDocumentChunk::create([
            'modul_document_id' => $document->id,
            'index_version' => 1,
            'chunk_index' => 0,
            'content' => 'Potongan teks pertama.',
            'embedding' => [0.1, 0.2, 0.3],
            'embedding_dimension' => 3,
        ])->fresh();

        $this->assertSame($document->id, $chunk->modul_document_id);
        $this->assertSame([0.1, 0.2, 0.3], $chunk->embedding);
        $this->assertSame(3, $chunk->embedding_dimension);
    }

    public function test_deleting_document_cascades_chunks(): void
    {
        if (! $this->isMySqlFamily()) {
            $this->markTestSkipped('Cascade assertions are MySQL/MariaDB specific.');
        }

        $document = $this->makeDocument();
        $this->makeChunks($document, 1, 2);

        DB::table('modul_documents')->where('id', $document->id)->delete();

        $this->assertSame(0, DB::table('modul_document_chunks')->where('modul_document_id', $document->id)->count());
    }

    public function test_deleting_modul_cascades_documents_and_chunks(): void
    {
        if (! $this->isMySqlFamily()) {
            $this->markTestSkipped('Cascade assertions are MySQL/MariaDB specific.');
        }

        $modul = Modul::factory()->create();
        $document = $this->makeDocument(['modul_id' => $modul->id]);
        $this->makeChunks($document, 1, 2);

        DB::table('moduls')->where('id', $modul->id)->delete();

        $this->assertSame(0, DB::table('modul_documents')->where('modul_id', $modul->id)->count());
        $this->assertSame(0, DB::table('modul_document_chunks')->where('modul_document_id', $document->id)->count());
    }

    public function test_document_requires_an_existing_modul(): void
    {
        $exception = $this->captureQueryException(fn () => ModulDocument::create([
            'modul_id' => 999999,
            'original_name' => 'orphan.pdf',
            'path' => 'modul-documents/orphan.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 10,
        ]));

        $this->assertSame('23000', $exception->getCode(), 'SQLSTATE harus menandakan FK violation.');
        $this->assertSame(0, ModulDocument::query()->count());
    }

    public function test_chunk_index_is_present_on_modul_document_id_and_index_version(): void
    {
        $document = $this->makeDocument();

        $indexes = collect(Schema::getIndexes('modul_document_chunks'))
            ->pluck('columns')
            ->map(fn (array $columns): array => array_values($columns));

        $this->assertTrue(
            $indexes->contains(fn (array $columns): bool => $columns === ['modul_document_id', 'index_version']),
            'Index (modul_document_id, index_version) harus ada.'
        );

        // The composite index supports lookups per document + version.
        $this->makeChunks($document, 1, 2);
        $this->makeChunks($document, 2, 1);

        $this->assertSame(
            2,
            DB::table('modul_document_chunks')
                ->where('modul_document_id', $document->id)
                ->where('index_version', 1)
                ->count()
        );
    }

    public function test_migration_down_then_up_restores_tables(): void
    {
        $document = $this->makeDocument();
        $this->makeChunks($document, 1, 2);

        $this->runMigration('down');

        $this->assertFalse(Schema::hasTable('modul_document_chunks'), 'down() harus menghapus modul_document_chunks.');
        $this->assertFalse(Schema::hasTable('modul_documents'), 'down() harus menghapus modul_documents.');
        $this->assertTrue(Schema::hasTable('moduls'), 'down() tidak boleh menyentuh tabel lain.');
        $this->assertTrue(Schema::hasTable('asistens'), 'down() tidak boleh menyentuh tabel lain.');

        $this->runMigration('up');

        $this->assertTrue(Schema::hasTable('modul_documents'), 'up() harus membuat ulang modul_documents.');
        $this->assertTrue(Schema::hasTable('modul_document_chunks'), 'up() harus membuat ulang modul_document_chunks.');

        foreach (['modul_id', 'original_name', 'path', 'mime', 'size_bytes', 'status', 'index_version', 'approved_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('modul_documents', $column), "up() harus membuat kembali {$column}.");
        }
        foreach (['modul_document_id', 'index_version', 'chunk_index', 'content', 'embedding'] as $column) {
            $this->assertTrue(Schema::hasColumn('modul_document_chunks', $column), "up() harus membuat kembali {$column}.");
        }

        $rebuilt = $this->makeDocument();
        $this->makeChunks($rebuilt, 1, 3);

        $this->assertSame(ModulDocument::STATUS_DRAFT, $rebuilt->fresh()->status);
        $this->assertSame(3, DB::table('modul_document_chunks')->where('modul_document_id', $rebuilt->id)->count());
    }

    private function isMySqlFamily(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeDocument(array $attributes = []): ModulDocument
    {
        return ModulDocument::create(array_merge([
            'modul_id' => Modul::factory()->create()->id,
            'original_name' => 'modul.pdf',
            'path' => 'modul-documents/modul.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
        ], $attributes))->fresh();
    }

    private function makeAssistant(): Asisten
    {
        $role = Role::create([
            'name' => 'TEST-MODUL-DOC',
            'guard_name' => 'asisten',
        ]);

        return Asisten::factory()->create(['role_id' => $role->id]);
    }

    private function makeChunks(ModulDocument $document, int $indexVersion, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            ModulDocumentChunk::create([
                'modul_document_id' => $document->id,
                'index_version' => $indexVersion,
                'chunk_index' => $i,
                'content' => "Chunk {$i} versi {$indexVersion}.",
            ]);
        }
    }

    private function captureQueryException(callable $callback): QueryException
    {
        try {
            $callback();
        } catch (QueryException $exception) {
            return $exception;
        }

        $this->fail('Expected the database to reject the invalid row.');
    }

    private function runMigration(string $direction): void
    {
        $path = collect(glob(database_path('migrations/*.php')))
            ->first(fn (string $file): bool => str_contains(basename($file), self::MIGRATION));

        $this->assertNotNull($path, 'Migration '.self::MIGRATION.' not found.');

        $migration = require $path;
        $migration->{$direction}();
    }
}
