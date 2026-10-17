<?php

namespace Tests\Feature;

use App\Models\Asisten;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Kelas;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SoalDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Model::unguard();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Model::reguard();

        parent::tearDown();
    }

    private function actingAsAssistantWithManageSoalPermission(): Asisten
    {
        $role = Role::create([
            'name' => 'soal-tp-delete-guard-tester',
            'guard_name' => 'asisten',
        ]);

        $permission = Permission::firstOrCreate([
            'name' => 'manage-soal',
            'guard_name' => 'asisten',
        ]);

        $role->givePermissionTo($permission);

        $assistant = Asisten::factory()->create([
            'role_id' => $role->id,
        ]);

        $assistant->assignRole($role);

        $this->actingAs($assistant, 'asisten');

        return $assistant;
    }

    private function createModul(): Modul
    {
        return Modul::factory()->create();
    }

    private function createSoal(Modul $modul, string $isi = 'Soal TP untuk pengujian guard hapus.'): SoalTp
    {
        return SoalTp::create([
            'modul_id' => $modul->id,
            'soal' => $isi,
        ]);
    }

    private function createJawaban(SoalTp $soal): JawabanTp
    {
        $kelas = Kelas::factory()->create();
        $praktikan = Praktikan::factory()->create(['kelas_id' => $kelas->id]);

        // jawaban_tps.submission_id is NOT NULL and unique per (submission_id,
        // soal_id), so a second answer on the same soal needs its own attempt.
        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $soal->modul_id,
            'tipe' => JawabanSubmission::TIPE_TP,
        ]);

        return JawabanTp::create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $soal->id,
            'modul_id' => $soal->modul_id,
            'submission_id' => $submission->id,
            'jawaban' => 'Jawaban praktikan untuk soal TP.',
        ]);
    }

    public function test_delete_soal_with_answers_returns_conflict_and_keeps_soal(): void
    {
        $this->actingAsAssistantWithManageSoalPermission();

        $soal = $this->createSoal($this->createModul());
        $this->createJawaban($soal);
        $this->createJawaban($soal);

        $response = $this->deleteJson("/api-v1/soal-tp/{$soal->id}");

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'SOAL_HAS_ANSWERS');
        $response->assertJsonPath('count', 2);
        $this->assertIsString($response->json('message'));
        $this->assertNotSame('', $response->json('message'));

        $row = DB::table('soal_tps')->where('id', $soal->id)->first();
        $this->assertNotNull($row, 'Baris soal_tps harus tetap ada setelah penolakan 409.');
        $this->assertNull($row->deleted_at, 'deleted_at harus tetap NULL setelah penolakan 409.');
        $this->assertSame(2, DB::table('jawaban_tps')->where('soal_id', $soal->id)->count());
    }

    public function test_delete_soal_without_answers_soft_deletes_and_returns_ok(): void
    {
        $this->actingAsAssistantWithManageSoalPermission();

        $soal = $this->createSoal($this->createModul());

        $response = $this->deleteJson("/api-v1/soal-tp/{$soal->id}");

        $response->assertOk();
        $response->assertJsonPath('message', 'Soal berhasil dihapus');

        $row = DB::table('soal_tps')->where('id', $soal->id)->first();
        $this->assertNotNull($row, 'Baris soal_tps harus tetap ada secara fisik (soft delete).');
        $this->assertNotNull($row->deleted_at, 'deleted_at harus terisi setelah soft delete.');
        $this->assertSoftDeleted('soal_tps', ['id' => $soal->id]);
    }

    public function test_delete_unknown_soal_returns_not_found(): void
    {
        $this->actingAsAssistantWithManageSoalPermission();

        $missingId = 999999;

        $response = $this->deleteJson("/api-v1/soal-tp/{$missingId}");

        $response->assertStatus(404);
        $response->assertJsonPath('message', "Soal dengan ID {$missingId} tidak ditemukan.");
        $this->assertArrayNotHasKey('code', $response->json());
    }
}
