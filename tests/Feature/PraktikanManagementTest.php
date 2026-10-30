<?php

namespace Tests\Feature;

use App\Models\Asisten;
use App\Models\Kelas;
use App\Models\LaporanPraktikan;
use App\Models\Modul;
use App\Models\Nilai;
use App\Models\Praktikan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PraktikanManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutCsrf();

        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::firstOrCreate([
            'name' => 'PRAKTIKAN',
            'guard_name' => 'praktikan',
        ]);
    }

    private function actingAsAssistantWithPermissions(array $permissions): Asisten
    {
        $role = Role::create([
            'name' => 'assistant-role',
            'guard_name' => 'asisten',
        ]);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'asisten',
            ]);
            $role->givePermissionTo($permission);
        }

        $assistant = Asisten::factory()->create([
            'role_id' => $role->id,
        ]);

        $assistant->assignRole($role);

        $this->actingAs($assistant, 'asisten');

        return $assistant;
    }

    public function test_can_list_praktikan_with_filters(): void
    {
        $this->actingAsAssistantWithPermissions(['praktikan-regist']);

        $kelasA = Kelas::factory()->create(['kelas' => 'IF-A']);
        $kelasB = Kelas::factory()->create(['kelas' => 'IF-B']);

        $praktikanA = Praktikan::factory()->create([
            'nim' => '11012230001',
            'nama' => 'Andi Setiawan',
            'email' => 'andi@example.com',
            'kelas_id' => $kelasA->id,
        ]);

        Praktikan::factory()->create([
            'nim' => '11012230002',
            'nama' => 'Budi Raharjo',
            'email' => 'budi@example.com',
            'kelas_id' => $kelasB->id,
        ]);

        $response = $this->getJson('/api-v1/praktikan?search=Andi&kelas_id='.$kelasA->id);

        $response->assertOk();

        $response->assertJsonStructure([
            'success',
            'filters',
            'data' => [
                [
                    'id',
                    'nama',
                    'nim',
                    'email',
                    'nomor_telepon',
                    'alamat',
                    'kelas_id',
                    'dk',
                ],
            ],
            'links',
            'meta',
        ]);

        $response->assertJsonFragment([
            'nim' => $praktikanA->nim,
        ]);

        $response->assertJsonMissing([
            'nim' => '11012230002',
        ]);
    }

    public function test_store_praktikan_assigns_role_and_hashes_password(): void
    {
        $this->actingAsAssistantWithPermissions(['praktikan-regist']);

        $kelas = Kelas::factory()->create();

        $payload = [
            'nama' => 'Citra Dewi',
            'nim' => '11012230003',
            'email' => 'citra@example.com',
            'nomor_telepon' => '081234567890',
            'alamat' => 'Jl. Sukamaju',
            'kelas_id' => $kelas->id,
            'dk' => 'DK1',
            'password' => 'praktikan123',
        ];

        $response = $this->postJson('/api-v1/praktikan', $payload);

        $response->assertCreated();

        $this->assertDatabaseHas('praktikans', [
            'nim' => '11012230003',
            'email' => 'citra@example.com',
            'kelas_id' => $kelas->id,
            'dk' => 'DK1',
        ]);

        $praktikan = Praktikan::where('nim', '11012230003')->firstOrFail();

        $this->assertTrue(Hash::check('praktikan123', $praktikan->password));
        $this->assertTrue($praktikan->hasRole('PRAKTIKAN'));
    }

    public function test_update_praktikan_without_password_retains_old_hash(): void
    {
        $this->actingAsAssistantWithPermissions(['praktikan-regist']);

        $kelas = Kelas::factory()->create();

        $praktikan = Praktikan::factory()->create([
            'kelas_id' => $kelas->id,
            'password' => Hash::make('old-secret'),
        ]);

        $response = $this->putJson('/api-v1/praktikan/'.$praktikan->id, [
            'nama' => 'Nama Baru',
            'nim' => $praktikan->nim,
            'email' => 'updated@example.com',
            'nomor_telepon' => '081111111111',
            'alamat' => 'Alamat Baru',
            'kelas_id' => $kelas->id,
            'dk' => 'DK1',
        ]);

        $response->assertOk();

        $praktikan->refresh();

        $this->assertEquals('Nama Baru', $praktikan->nama);
        $this->assertEquals('updated@example.com', $praktikan->email);
        $this->assertTrue(Hash::check('old-secret', $praktikan->password));
    }

    public function test_update_praktikan_with_new_password(): void
    {
        $this->actingAsAssistantWithPermissions(['praktikan-regist']);

        $kelas = Kelas::factory()->create();

        $praktikan = Praktikan::factory()->create([
            'kelas_id' => $kelas->id,
            'password' => Hash::make('old-secret'),
        ]);

        $response = $this->putJson('/api-v1/praktikan/'.$praktikan->id, [
            'nama' => $praktikan->nama,
            'nim' => $praktikan->nim,
            'email' => $praktikan->email,
            'nomor_telepon' => $praktikan->nomor_telepon,
            'alamat' => $praktikan->alamat,
            'kelas_id' => $kelas->id,
            'dk' => 'DK1',
            'password' => 'new-secret',
        ]);

        $response->assertOk();

        $praktikan->refresh();

        $this->assertTrue(Hash::check('new-secret', $praktikan->password));
    }

    public function test_delete_praktikan_removes_record(): void
    {
        $this->actingAsAssistantWithPermissions(['praktikan-regist']);

        $praktikan = Praktikan::factory()->create();

        $response = $this->deleteJson('/api-v1/praktikan/'.$praktikan->id);

        $response->assertOk();

        $this->assertDatabaseMissing('praktikans', [
            'id' => $praktikan->id,
        ]);
    }

    public function test_unmarked_summary_counts_ungraded_reports(): void
    {
        $assistant = $this->actingAsAssistantWithPermissions(['see-pelanggaran']);

        $role = Role::firstOrCreate([
            'name' => 'additional-role',
            'guard_name' => 'asisten',
        ]);

        $otherAssistant = Asisten::factory()->create([
            'role_id' => $role->id,
        ]);
        $otherAssistant->assignRole($role);

        $kelas = Kelas::factory()->create();
        $modulA = Modul::factory()->create();
        $modulB = Modul::factory()->create();

        $praktikanA = Praktikan::factory()->create(['kelas_id' => $kelas->id]);
        $praktikanB = Praktikan::factory()->create(['kelas_id' => $kelas->id]);
        $praktikanC = Praktikan::factory()->create(['kelas_id' => $kelas->id]);

        LaporanPraktikan::query()->create([
            'pesan' => 'Perlu penilaian',
            'praktikan_id' => $praktikanA->id,
            'asisten_id' => $assistant->id,
            'modul_id' => $modulA->id,
        ]);

        $withScore = LaporanPraktikan::query()->create([
            'pesan' => 'Sudah dinilai',
            'praktikan_id' => $praktikanB->id,
            'asisten_id' => $assistant->id,
            'modul_id' => $modulB->id,
        ]);

        Nilai::query()->create([
            'tp' => 80,
            'ta' => 85,
            'd1' => 90,
            'd2' => 88,
            'd3' => 92,
            'd4' => 87,
            'l1' => 89,
            'l2' => 90,
            'avg' => 87,
            'modul_id' => $withScore->modul_id,
            'asisten_id' => $withScore->asisten_id,
            'kelas_id' => $kelas->id,
            'praktikan_id' => $withScore->praktikan_id,
        ]);

        LaporanPraktikan::query()->create([
            'pesan' => 'Belum dinilai',
            'praktikan_id' => $praktikanC->id,
            'asisten_id' => $otherAssistant->id,
            'modul_id' => $modulA->id,
        ]);

        $response = $this->getJson('/api-v1/laporan/unmarked-summary');

        $response->assertOk();

        $response->assertJsonFragment([
            'id' => $assistant->id,
        ]);

        $payload = collect($response->json('data'));

        $assistantEntry = $payload->firstWhere('asisten.id', $assistant->id);
        $otherEntry = $payload->firstWhere('asisten.id', $otherAssistant->id);

        $this->assertEquals(1, $assistantEntry['totals']['laporan'] ?? null);
        $this->assertEquals(1, $assistantEntry['totals']['praktikan'] ?? null);
        $this->assertEquals(1, $otherEntry['totals']['laporan'] ?? null);
        $this->assertEquals(1, $otherEntry['totals']['praktikan'] ?? null);
    }
}
