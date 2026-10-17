<?php

namespace Tests\Feature;

use App\Http\Controllers\API\ModulDocumentController;
use App\Models\Asisten;
use App\Models\Modul;
use App\Models\ModulDocument;
use App\Models\Praktikan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers POST /api-v1/modul/{id}/documents.
 *
 * An assistant holding `manage-modul-documents` uploads a PDF/DOCX (<= 20 MB);
 * the file lands on the `local` disk under modul-documents/ and a
 * modul_documents row is created as `draft` with its sha256 checksum.
 */
class ModulDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'manage-modul-documents';

    /** Permission/middleware must run: no withoutMiddleware() on purpose. */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_upload_pdf_returns_202_with_draft_document_and_stores_file(): void
    {
        $assistant = $this->actingAsAssistantWithPermission();
        $modul = Modul::factory()->create();
        $file = UploadedFile::fake()->create('materi-modul.pdf', 512, 'application/pdf');

        $response = $this->postJson("/api-v1/modul/{$modul->id}/documents", [
            'file' => $file,
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.modul_id', $modul->id)
            ->assertJsonPath('data.original_name', 'materi-modul.pdf')
            ->assertJsonPath('data.status', ModulDocument::STATUS_DRAFT)
            ->assertJsonPath('data.uploaded_by_asisten_id', $assistant->id)
            ->assertJsonPath('data.size_bytes', 512 * 1024)
            ->assertJsonPath('data.mime', 'application/pdf');

        $document = ModulDocument::query()->sole();

        $this->assertSame(ModulDocument::STATUS_DRAFT, $document->status);
        $this->assertSame($modul->id, $document->modul_id);
        $this->assertSame($assistant->id, $document->uploaded_by_asisten_id);
        $this->assertSame('materi-modul.pdf', $document->original_name);
        $this->assertSame(512 * 1024, $document->size_bytes);
        $this->assertSame(0, $document->index_version);
        $this->assertStringStartsWith(ModulDocumentController::DIRECTORY.'/', $document->path);

        Storage::disk('local')->assertExists($document->path);
        $this->assertSame(
            hash('sha256', Storage::disk('local')->get($document->path)),
            $document->checksum_sha256
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $document->checksum_sha256);
    }

    public function test_upload_docx_is_accepted(): void
    {
        $this->actingAsAssistantWithPermission();
        $modul = Modul::factory()->create();
        $file = UploadedFile::fake()->create('materi-modul.docx', 128, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->postJson("/api-v1/modul/{$modul->id}/documents", ['file' => $file])
            ->assertStatus(202)
            ->assertJsonPath('data.status', ModulDocument::STATUS_DRAFT);

        Storage::disk('local')->assertExists(ModulDocument::query()->sole()->path);
    }

    public function test_executable_file_is_rejected_with_422(): void
    {
        $this->actingAsAssistantWithPermission();
        $modul = Modul::factory()->create();
        $file = UploadedFile::fake()->create('malware.exe', 64, 'application/x-msdownload');

        $this->postJson("/api-v1/modul/{$modul->id}/documents", ['file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, ModulDocument::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('modul-documents'));
    }

    public function test_file_larger_than_20mb_is_rejected_with_422(): void
    {
        $this->actingAsAssistantWithPermission();
        $modul = Modul::factory()->create();
        $file = UploadedFile::fake()->create('besar.pdf', 20481, 'application/pdf');

        $this->postJson("/api-v1/modul/{$modul->id}/documents", ['file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, ModulDocument::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('modul-documents'));
    }

    public function test_missing_file_is_rejected_with_422(): void
    {
        $this->actingAsAssistantWithPermission();
        $modul = Modul::factory()->create();

        $this->postJson("/api-v1/modul/{$modul->id}/documents", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, ModulDocument::query()->count());
    }

    public function test_assistant_without_permission_is_forbidden(): void
    {
        $this->actingAsAssistantWithPermission([]);
        $modul = Modul::factory()->create();
        $file = UploadedFile::fake()->create('materi.pdf', 64, 'application/pdf');

        $this->postJson("/api-v1/modul/{$modul->id}/documents", ['file' => $file])
            ->assertStatus(403);

        $this->assertSame(0, ModulDocument::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('modul-documents'));
    }

    public function test_praktikan_is_forbidden(): void
    {
        $praktikan = Praktikan::factory()->create();
        $this->actingAs($praktikan, 'praktikan');

        $modul = Modul::factory()->create();
        $file = UploadedFile::fake()->create('materi.pdf', 64, 'application/pdf');

        $response = $this->postJson("/api-v1/modul/{$modul->id}/documents", ['file' => $file]);

        $this->assertContains($response->status(), [401, 403]);
        $this->assertSame(0, ModulDocument::query()->count());
    }

    public function test_guest_is_unauthenticated(): void
    {
        $modul = Modul::factory()->create();
        $file = UploadedFile::fake()->create('materi.pdf', 64, 'application/pdf');

        $this->postJson("/api-v1/modul/{$modul->id}/documents", ['file' => $file])
            ->assertStatus(401);
    }

    public function test_unknown_modul_returns_404(): void
    {
        $this->actingAsAssistantWithPermission();
        $file = UploadedFile::fake()->create('materi.pdf', 64, 'application/pdf');

        $this->postJson('/api-v1/modul/999999/documents', ['file' => $file])
            ->assertStatus(404)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->assertSame(0, ModulDocument::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('modul-documents'));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actingAsAssistantWithPermission(array $permissions = [self::PERMISSION]): Asisten
    {
        $role = Role::firstOrCreate(['name' => 'MODUL-DOC-UPLOADER', 'guard_name' => 'asisten']);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'asisten']);
        }

        $role->syncPermissions($permissions);

        $assistant = Asisten::factory()->create(['role_id' => $role->id]);
        $assistant->assignRole($role);

        $this->actingAs($assistant, 'asisten');

        return $assistant;
    }
}
