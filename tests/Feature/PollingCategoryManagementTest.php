<?php

namespace Tests\Feature;

use App\Models\Asisten;
use App\Models\JenisPolling;
use App\Models\Polling;
use App\Models\Praktikan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PollingCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutCsrf();

        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function actingAsAssistant(): Asisten
    {
        $role = Role::firstOrCreate([
            'name' => 'testing-assistant',
            'guard_name' => 'asisten',
        ]);

        $assistant = Asisten::factory()->create([
            'role_id' => $role->id,
        ]);

        $assistant->assignRole($role);

        $this->actingAs($assistant, 'asisten');

        return $assistant;
    }

    public function test_assistant_can_delete_polling_category_without_pollings(): void
    {
        $this->actingAsAssistant();

        $category = JenisPolling::factory()->create();

        $response = $this->deleteJson("/api-v1/jenis-polling/{$category->id}");

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseMissing('jenis_pollings', [
            'id' => $category->id,
        ]);
    }

    public function test_cannot_delete_polling_category_when_pollings_exist(): void
    {
        $assistant = $this->actingAsAssistant();

        $category = JenisPolling::factory()->create();
        $praktikan = Praktikan::factory()->create();

        Polling::create([
            'polling_id' => $category->id,
            'asisten_id' => $assistant->id,
            'praktikan_id' => $praktikan->id,
        ]);

        $response = $this->deleteJson("/api-v1/jenis-polling/{$category->id}");

        $response
            ->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);

        $this->assertDatabaseHas('jenis_pollings', [
            'id' => $category->id,
        ]);
    }
}
