<?php

namespace Tests\Feature;

use App\Http\Requests\RejectModulDocumentRequest;
use App\Jobs\IngestModulDocument;
use App\Models\Asisten;
use App\Models\Modul;
use App\Models\ModulDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers POST /api-v1/modul-documents/{id}/approve and /reject.
 *
 * Only a DDC (holder of `approve-modul-documents`) may decide on a draft:
 * approve -> 202, status `approved`, approved_by/approved_at set and an
 * IngestModulDocument job pushed; reject -> 200 with a reject_reason
 * (min 5 chars, otherwise 422). Any non-draft document -> 409. ATC only
 * holds `manage-modul-documents` (upload), so it gets 403.
 */
class ModulDocumentApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const APPROVE_PERMISSION = 'approve-modul-documents';

    private const UPLOAD_PERMISSION = 'manage-modul-documents';

    /** Permission/middleware must run: no withoutMiddleware() on purpose. */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_ddc_approves_draft_and_dispatches_ingest(): void
    {
        $ddc = $this->actingAsAssistant('DDC', [self::APPROVE_PERMISSION]);
        $document = $this->draftDocument();

        $response = $this->postJson("/api-v1/modul-documents/{$document->id}/approve");

        $response->assertStatus(202)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', ModulDocument::STATUS_APPROVED)
            ->assertJsonPath('data.approved_by_asisten_id', $ddc->id);

        $fresh = $document->refresh();

        $this->assertSame(ModulDocument::STATUS_APPROVED, $fresh->status);
        $this->assertSame($ddc->id, $fresh->approved_by_asisten_id);
        $this->assertNotNull($fresh->approved_at);
        $this->assertNull($fresh->reject_reason);

        Queue::assertPushed(IngestModulDocument::class, function (IngestModulDocument $job) use ($document): bool {
            return $job->document->getKey() === $document->id;
        });
    }

    public function test_atc_cannot_approve(): void
    {
        $this->actingAsAssistant('ATC', [self::UPLOAD_PERMISSION]);
        $document = $this->draftDocument();

        $this->postJson("/api-v1/modul-documents/{$document->id}/approve")
            ->assertStatus(403);

        $fresh = $document->refresh();

        $this->assertSame(ModulDocument::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->approved_by_asisten_id);
        Queue::assertNothingPushed();
    }

    public function test_atc_cannot_reject(): void
    {
        $this->actingAsAssistant('ATC', [self::UPLOAD_PERMISSION]);
        $document = $this->draftDocument();

        $this->postJson("/api-v1/modul-documents/{$document->id}/reject", ['reason' => 'Berkas tidak sesuai modul.'])
            ->assertStatus(403);

        $this->assertSame(ModulDocument::STATUS_DRAFT, $document->refresh()->status);
    }

    public function test_reject_without_reason_returns_422(): void
    {
        $this->actingAsAssistant('DDC', [self::APPROVE_PERMISSION]);
        $document = $this->draftDocument();

        $this->postJson("/api-v1/modul-documents/{$document->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('reason');

        $fresh = $document->refresh();

        $this->assertSame(ModulDocument::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->reject_reason);
    }

    public function test_reject_with_too_short_reason_returns_422(): void
    {
        $this->actingAsAssistant('DDC', [self::APPROVE_PERMISSION]);
        $document = $this->draftDocument();

        $this->postJson("/api-v1/modul-documents/{$document->id}/reject", ['reason' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(ModulDocument::STATUS_DRAFT, $document->refresh()->status);
    }

    public function test_reject_with_reason_returns_200_and_stores_reason(): void
    {
        $this->actingAsAssistant('DDC', [self::APPROVE_PERMISSION]);
        $document = $this->draftDocument();
        $reason = 'Dokumen tidak relevan dengan modul ini.';

        $this->assertGreaterThanOrEqual(RejectModulDocumentRequest::REASON_MIN_LENGTH, mb_strlen($reason));

        $this->postJson("/api-v1/modul-documents/{$document->id}/reject", ['reason' => $reason])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', ModulDocument::STATUS_REJECTED)
            ->assertJsonPath('data.reject_reason', $reason);

        $fresh = $document->refresh();

        $this->assertSame(ModulDocument::STATUS_REJECTED, $fresh->status);
        $this->assertSame($reason, $fresh->reject_reason);
        $this->assertNull($fresh->approved_by_asisten_id);
        Queue::assertNothingPushed();
    }

    public function test_approve_non_draft_document_returns_409(): void
    {
        $this->actingAsAssistant('DDC', [self::APPROVE_PERMISSION]);

        foreach ([ModulDocument::STATUS_APPROVED, ModulDocument::STATUS_REJECTED, ModulDocument::STATUS_ACTIVE] as $status) {
            $document = $this->draftDocument($status);

            $this->postJson("/api-v1/modul-documents/{$document->id}/approve")
                ->assertStatus(409)
                ->assertJsonPath('code', 'INVALID_STATUS');

            $this->assertSame($status, $document->refresh()->status);
        }

        Queue::assertNothingPushed();
    }

    public function test_reject_non_draft_document_returns_409(): void
    {
        $this->actingAsAssistant('DDC', [self::APPROVE_PERMISSION]);

        foreach ([ModulDocument::STATUS_APPROVED, ModulDocument::STATUS_REJECTED, ModulDocument::STATUS_INDEXING] as $status) {
            $document = $this->draftDocument($status);

            $this->postJson("/api-v1/modul-documents/{$document->id}/reject", ['reason' => 'Alasan penolakan.'])
                ->assertStatus(409)
                ->assertJsonPath('code', 'INVALID_STATUS');

            $this->assertSame($status, $document->refresh()->status);
        }
    }

    public function test_unknown_document_returns_404(): void
    {
        $this->actingAsAssistant('DDC', [self::APPROVE_PERMISSION]);

        $this->postJson('/api-v1/modul-documents/999999/approve')
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->postJson('/api-v1/modul-documents/999999/reject', ['reason' => 'Alasan penolakan.'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_guest_is_unauthenticated(): void
    {
        $document = $this->draftDocument();

        $this->postJson("/api-v1/modul-documents/{$document->id}/approve")
            ->assertStatus(401);

        $this->assertSame(ModulDocument::STATUS_DRAFT, $document->refresh()->status);
    }

    private function draftDocument(string $status = ModulDocument::STATUS_DRAFT): ModulDocument
    {
        $modul = Modul::factory()->create();

        return ModulDocument::query()->create([
            'modul_id' => $modul->id,
            'original_name' => 'materi-modul.pdf',
            'path' => 'modul-documents/materi-modul.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'status' => $status,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actingAsAssistant(string $roleName, array $permissions): Asisten
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'asisten']);

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
