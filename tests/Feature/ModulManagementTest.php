<?php

namespace Tests\Feature;

use App\Models\Asisten;
use App\Models\Modul;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ModulManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
    }

    protected function actingAsAssistantWithPermissions(array $permissions): Asisten
    {
        $role = Role::create([
            'name' => 'TEST-ROLE',
            'guard_name' => 'asisten',
        ]);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'asisten',
            ]);
        }

        $role->syncPermissions($permissions);

        $assistant = Asisten::factory()->create([
            'role_id' => $role->id,
        ]);

        $assistant->assignRole($role);

        $this->actingAs($assistant, 'asisten');

        return $assistant;
    }

    public function test_assistant_can_create_module_with_learning_point(): void
    {
        $this->actingAsAssistantWithPermissions(['manage-modul']);

        $payload = [
            'judul' => 'Modul Integrasi',
            'deskripsi' => 'Mahasiswa mampu memahami konsep integral.',
            'isEnglish' => 0,
            'isUnlocked' => 1,
            'modul_link' => 'https://example.com/modul.pdf',
            'ppt_link' => 'https://example.com/slides.pptx',
            'video_link' => 'https://youtu.be/example',
        ];

        $session = $this->app['session'];
        $session->start();
        $token = $session->token();

        $response = $this->post('/api-v1/modul', $payload + ['_token' => $token]);

        // The API answers with the created resource, never a redirect: a 302
        // loses the payload once the client follows it with a GET.
        $response
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('moduls', [
            'judul' => $payload['judul'],
            'deskripsi' => $payload['deskripsi'],
        ]);
    }

    public function test_creating_module_returns_json_payload_instead_of_redirect(): void
    {
        $this->actingAsAssistantWithPermissions(['manage-modul']);

        $payload = [
            'judul' => 'Modul JSON Seam',
            'deskripsi' => 'Modul harus dikonfirmasi lewat JSON.',
            'isEnglish' => 0,
            'isUnlocked' => 0,
        ];

        // No `from()` on purpose: a browser XHR sends no Referer, so the old
        // `redirect()->back()` fell back to the current page and the client
        // followed it with a body-less GET (422 at best, "Network Error" at
        // worst). The API must answer with the created resource instead.
        $response = $this->postJson('/api-v1/modul', $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.judul', $payload['judul'])
            ->assertJsonPath('data.deskripsi', $payload['deskripsi']);

        $this->assertDatabaseHas('moduls', [
            'judul' => $payload['judul'],
            'deskripsi' => $payload['deskripsi'],
        ]);
    }

    public function test_inertia_client_gets_redirect_to_module_page_not_json(): void
    {
        $this->actingAsAssistantWithPermissions(['manage-modul']);

        $payload = [
            'judul' => 'Modul Inertia Seam',
            'deskripsi' => 'Inertia harus menerima redirect, bukan JSON.',
            'isEnglish' => 0,
            'isUnlocked' => 0,
        ];

        // The form posts through Inertia's `router.visit`, which sends
        // `X-Inertia: true` and only runs `onSuccess` when the response carries
        // an Inertia header. A plain JSON 201 leaves the browser stuck with no
        // confirmation and a stale list.
        $response = $this->withHeader('X-Inertia', 'true')
            ->from('/modul')
            ->post('/api-v1/modul', $payload);

        $response->assertRedirect('/modul');

        $this->assertDatabaseHas('moduls', [
            'judul' => $payload['judul'],
        ]);
    }

    public function test_learning_point_is_required_when_creating_module(): void
    {
        $this->actingAsAssistantWithPermissions(['manage-modul']);

        $payload = [
            'judul' => 'Modul Tanpa Poin',
            'isEnglish' => 0,
            'isUnlocked' => 0,
        ];

        $session = $this->app['session'];
        $session->start();
        $token = $session->token();

        $response = $this->from('/modul')->post('/api-v1/modul', $payload + ['_token' => $token]);

        // `/api-v1/*` always answers validation failures with a JSON 422
        // (`RenderApiException`), never a redirect back to `/modul`.
        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['deskripsi']);

        $this->assertSame(
            ['The deskripsi field is required.'],
            $response->json('errors.deskripsi'),
            'Learning point (deskripsi) must be reported as a required field.'
        );

        $this->assertDatabaseMissing('moduls', [
            'judul' => $payload['judul'],
        ]);
    }

    public function test_bulk_update_accepts_top_level_array_payload(): void
    {
        $this->actingAsAssistantWithPermissions(['manage-modul']);

        $modules = Modul::factory()->count(2)->create([
            'isUnlocked' => false,
            'isEnglish' => false,
        ]);

        $payload = $modules
            ->map(fn (Modul $module) => [
                'id' => $module->id,
                'isUnlocked' => 1,
                'isEnglish' => 1,
            ])
            ->all();

        $response = $this->patchJson('/api-v1/modul/bulk-update', $payload);

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Bulk update berhasil',
                'updated' => 2,
            ]);

        foreach ($modules as $module) {
            $this->assertDatabaseHas('moduls', [
                'id' => $module->id,
                'isUnlocked' => true,
                'isEnglish' => true,
            ]);
        }
    }

    public function test_bulk_update_requires_valid_module_ids(): void
    {
        $this->actingAsAssistantWithPermissions(['manage-modul']);

        $response = $this->patchJson('/api-v1/modul/bulk-update', [
            ['id' => 9999, 'isUnlocked' => 1],
        ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Validasi gagal.',
            ])
            ->assertJsonValidationErrors(['payload.0.id']);
    }
}
