<?php

namespace Tests\Feature;

use App\Models\Kelas;
use App\Models\Modul;
use App\Models\Praktikan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AutosaveSnapshotControllerTest extends TestCase
{
    use RefreshDatabase;

    private Praktikan $praktikan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutCsrf();

        config([
            'cache.snapshot_store' => 'array',
        ]);

        Cache::store('array')->flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::create([
            'name' => 'praktikan-tester',
            'guard_name' => 'praktikan',
        ]);

        $permission = Permission::firstOrCreate([
            'name' => 'praktikum-lms',
            'guard_name' => 'praktikan',
        ]);

        $role->givePermissionTo($permission);

        $kelas = Kelas::factory()->create();
        $this->praktikan = Praktikan::factory()->create([
            'kelas_id' => $kelas->id,
        ]);

        $this->praktikan->assignRole($role);
        $this->actingAs($this->praktikan, 'praktikan');
    }

    public function test_praktikan_can_store_and_retrieve_snapshot(): void
    {
        $modul = Modul::factory()->create();

        $payload = [
            'praktikan_id' => $this->praktikan->id,
            'modul_id' => $modul->id,
            'tipe_soal' => 'ta',
            'jawaban' => [
                '101' => 3,
                '102' => 2,
            ],
        ];

        $this->postJson('/api-v1/praktikan/autosave', $payload)
            ->assertOk()
            ->assertJsonFragment(['success' => true])
            ->assertJsonPath('data.jawaban.101', 3);

        $response = $this->getJson(sprintf(
            '/api-v1/praktikan/autosave?praktikan_id=%d&modul_id=%d&tipe_soal=ta',
            $this->praktikan->id,
            $modul->id
        ));

        $response->assertOk()->assertJsonPath('data.0.praktikan_id', $this->praktikan->id);
        $response->assertJsonPath('data.0.modul_id', $modul->id);
        $response->assertJsonPath('data.0.jawaban.102', 2);
    }

    public function test_praktikan_can_clear_snapshot(): void
    {
        $modul = Modul::factory()->create();

        $payload = [
            'praktikan_id' => $this->praktikan->id,
            'modul_id' => $modul->id,
            'tipe_soal' => 'tk',
            'jawaban' => [
                '201' => 1,
            ],
        ];

        $this->postJson('/api-v1/praktikan/autosave', $payload)->assertOk();

        $this->deleteJson('/api-v1/praktikan/autosave', [
            'praktikan_id' => $this->praktikan->id,
            'modul_id' => $modul->id,
            'tipe_soal' => 'tk',
        ])
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->getJson(sprintf(
            '/api-v1/praktikan/autosave?praktikan_id=%d&modul_id=%d&tipe_soal=tk',
            $this->praktikan->id,
            $modul->id
        ))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_praktikan_can_store_question_ids_once(): void
    {
        $modul = Modul::factory()->create();

        $payload = [
            'praktikan_id' => $this->praktikan->id,
            'modul_id' => $modul->id,
            'tipe_soal' => 'ta',
            'question_ids' => [11, 12, 13],
        ];

        $this->postJson('/api-v1/praktikan/autosave/questions', $payload)
            ->assertOk()
            ->assertJsonFragment(['success' => true])
            ->assertJsonPath('question_ids', [11, 12, 13]);

        $this->postJson('/api-v1/praktikan/autosave/questions', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Question IDs already stored.')
            ->assertJsonPath('question_ids', [11, 12, 13]);

        $this->getJson(sprintf(
            '/api-v1/praktikan/autosave/questions?praktikan_id=%d&modul_id=%d&tipe_soal=ta',
            $this->praktikan->id,
            $modul->id
        ))
            ->assertOk()
            ->assertJsonPath('has_stored_questions', true)
            ->assertJsonPath('question_ids', [11, 12, 13]);
    }
}
