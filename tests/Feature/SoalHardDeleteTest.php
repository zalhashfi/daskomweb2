<?php

namespace Tests\Feature;

use App\Models\Asisten;
use App\Models\AuditLog;
use App\Models\JawabanMandiri;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Kelas;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalMandiri;
use App\Models\SoalTp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SoalHardDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const HARD_DELETE_ROLE = 'KORDAS';

    private const HARD_DELETE_LESS_ROLE = 'ATC';

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

    /**
     * Replikasi matriks RolePermissionsSeeder untuk permission yang dipakai test.
     *
     * @return array<string, list<string>>
     */
    private function rolePermissionMatrix(): array
    {
        return [
            self::HARD_DELETE_ROLE => ['manage-role', 'manage-soal'],
            self::HARD_DELETE_LESS_ROLE => ['manage-soal'],
        ];
    }

    private function seedRolesAndPermissions(): void
    {
        foreach ($this->rolePermissionMatrix() as $roleName => $permissionNames) {
            $role = Role::create([
                'name' => $roleName,
                'guard_name' => 'asisten',
            ]);

            foreach ($permissionNames as $permissionName) {
                $permission = Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'asisten',
                ]);

                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function actingAsAssistantWithRole(string $roleName): Asisten
    {
        $role = Role::findByName($roleName, 'asisten');

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

    private function createSoalTp(Modul $modul, string $isi = 'Soal TP untuk pengujian hard delete.'): SoalTp
    {
        return SoalTp::create([
            'modul_id' => $modul->id,
            'soal' => $isi,
        ]);
    }

    private function createSoalTm(Modul $modul, string $isi = 'Soal TM untuk pengujian hard delete.'): SoalMandiri
    {
        return SoalMandiri::create([
            'modul_id' => $modul->id,
            'soal' => $isi,
        ]);
    }

    private function createJawabanTp(SoalTp $soal): JawabanTp
    {
        $kelas = Kelas::factory()->create();
        $praktikan = Praktikan::factory()->create(['kelas_id' => $kelas->id]);

        // jawaban_tps.submission_id is NOT NULL (FK to jawaban_submissions).
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

    private function createJawabanTm(SoalMandiri $soal): JawabanMandiri
    {
        $kelas = Kelas::factory()->create();
        $praktikan = Praktikan::factory()->create(['kelas_id' => $kelas->id]);

        return JawabanMandiri::create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $soal->id,
            'modul_id' => $soal->modul_id,
            'jawaban' => 'Jawaban praktikan untuk soal TM.',
        ]);
    }

    /**
     * @return list<array{string, string}> [route prefix, table]
     */
    private function soalVariants(): array
    {
        return [
            ['soal-tp', 'soal_tps'],
            ['soal-tm', 'soal_mandiris'],
        ];
    }

    // ------------------------------------------------------------------
    // A. Guard soft delete yang sama untuk TP dan TM
    // ------------------------------------------------------------------

    public function test_soft_delete_without_answers_returns_ok_for_tp_and_tm(): void
    {
        $this->seedRolesAndPermissions();
        $this->actingAsAssistantWithRole(self::HARD_DELETE_ROLE);

        $modul = $this->createModul();

        $soalTp = $this->createSoalTp($modul);
        $soalTm = $this->createSoalTm($modul);

        $this->deleteJson("/api-v1/soal-tp/{$soalTp->id}")->assertOk();
        $this->deleteJson("/api-v1/soal-tm/{$soalTm->id}")->assertOk();

        $this->assertSoftDeleted('soal_tps', ['id' => $soalTp->id]);
        $this->assertSoftDeleted('soal_mandiris', ['id' => $soalTm->id]);
    }

    public function test_soft_delete_with_answers_returns_conflict_for_tp_and_tm(): void
    {
        $this->seedRolesAndPermissions();
        $this->actingAsAssistantWithRole(self::HARD_DELETE_ROLE);

        $modul = $this->createModul();

        $soalTp = $this->createSoalTp($modul);
        $soalTm = $this->createSoalTm($modul);

        $this->createJawabanTp($soalTp);
        $this->createJawabanTm($soalTm);

        foreach ([
            ["/api-v1/soal-tp/{$soalTp->id}", 'soal_tps'],
            ["/api-v1/soal-tm/{$soalTm->id}", 'soal_mandiris'],
        ] as [$url, $table]) {
            $response = $this->deleteJson($url);

            $response->assertStatus(409);
            $response->assertJsonPath('code', 'SOAL_HAS_ANSWERS');
            $response->assertJsonPath('count', 1);

            $row = DB::table($table)->where('id', (int) basename($url))->first();
            $this->assertNotNull($row);
            $this->assertNull($row->deleted_at);
        }
    }

    public function test_soft_delete_unknown_soal_returns_not_found_for_tp_and_tm(): void
    {
        $this->seedRolesAndPermissions();
        $this->actingAsAssistantWithRole(self::HARD_DELETE_ROLE);

        $missingId = 999999;

        $this->deleteJson("/api-v1/soal-tp/{$missingId}")
            ->assertStatus(404)
            ->assertJsonPath('message', "Soal dengan ID {$missingId} tidak ditemukan.");

        $this->deleteJson("/api-v1/soal-tm/{$missingId}")
            ->assertStatus(404)
            ->assertJsonPath('message', "Soal dengan ID {$missingId} tidak ditemukan.");
    }

    // ------------------------------------------------------------------
    // B1. force=1 tanpa permission manage-role -> 403
    // ------------------------------------------------------------------

    public function test_force_delete_without_manage_role_permission_is_forbidden_for_tp_and_tm(): void
    {
        $this->seedRolesAndPermissions();
        $assistant = $this->actingAsAssistantWithRole(self::HARD_DELETE_LESS_ROLE);

        $this->assertFalse($assistant->hasPermissionTo('manage-role', 'asisten'));
        $this->assertTrue($assistant->hasPermissionTo('manage-soal', 'asisten'));

        $modul = $this->createModul();

        $soalTp = $this->createSoalTp($modul);
        $soalTm = $this->createSoalTm($modul);

        foreach ([
            ["/api-v1/soal-tp/{$soalTp->id}?force=1", 'soal_tps', $soalTp->id],
            ["/api-v1/soal-tm/{$soalTm->id}?force=1", 'soal_mandiris', $soalTm->id],
        ] as [$url, $table, $id]) {
            $response = $this->deleteJson($url);

            $response->assertStatus(403);
            $response->assertJsonPath('code', 'FORBIDDEN');
            $this->assertIsString($response->json('message'));
            $this->assertNotSame('', $response->json('message'));

            $row = DB::table($table)->where('id', $id)->first();
            $this->assertNotNull($row, "Baris {$table} harus tetap ada setelah penolakan 403.");
            $this->assertNull($row->deleted_at, 'deleted_at harus tetap NULL setelah penolakan 403.');
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'soal.hard_delete')->count());
    }

    // ------------------------------------------------------------------
    // B2. force=1 dengan manage-role tapi soal berjawaban -> 409
    // ------------------------------------------------------------------

    public function test_force_delete_with_answers_returns_conflict_and_keeps_row_for_tp_and_tm(): void
    {
        $this->seedRolesAndPermissions();
        $this->actingAsAssistantWithRole(self::HARD_DELETE_ROLE);

        $modul = $this->createModul();

        $soalTp = $this->createSoalTp($modul);
        $soalTm = $this->createSoalTm($modul);

        $this->createJawabanTp($soalTp);
        $this->createJawabanTm($soalTm);

        foreach ([
            ["/api-v1/soal-tp/{$soalTp->id}?force=1", 'soal_tps', $soalTp->id],
            ["/api-v1/soal-tm/{$soalTm->id}?force=1", 'soal_mandiris', $soalTm->id],
        ] as [$url, $table, $id]) {
            $response = $this->deleteJson($url);

            $response->assertStatus(409);
            $response->assertJsonPath('code', 'SOAL_HAS_ANSWERS');
            $response->assertJsonPath('count', 1);

            $row = DB::table($table)->where('id', $id)->first();
            $this->assertNotNull($row, "Baris {$table} harus tetap ada setelah penolakan 409.");
            $this->assertNull($row->deleted_at);
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'soal.hard_delete')->count());
    }

    // ------------------------------------------------------------------
    // B3. force=1 dengan manage-role tanpa jawaban -> hard delete + audit
    // ------------------------------------------------------------------

    public function test_force_delete_removes_row_and_writes_audit_for_tp(): void
    {
        $this->seedRolesAndPermissions();
        $assistant = $this->actingAsAssistantWithRole(self::HARD_DELETE_ROLE);

        $modul = $this->createModul();
        $soal = $this->createSoalTp($modul, 'Soal TP yang akan dihapus permanen.');
        $soalId = $soal->id;

        $response = $this->deleteJson("/api-v1/soal-tp/{$soalId}?force=1");

        $response->assertOk();
        $response->assertJsonPath('status', 'success');

        $this->assertNull(SoalTp::withTrashed()->find($soalId), 'Baris soal_tps harus hilang secara fisik.');
        $this->assertSame(0, DB::table('soal_tps')->where('id', $soalId)->count());

        $logs = AuditLog::query()->where('action', 'soal.hard_delete')->get();
        $this->assertCount(1, $logs);

        $log = $logs->first();
        $this->assertSame('soal', $log->subject_type);
        $this->assertSame($soalId, $log->subject_id);
        $this->assertSame('asisten', $log->actor_type);
        $this->assertSame($assistant->id, $log->actor_id);
        $this->assertSame('soal_tp', data_get($log->metadata, 'model'));
        $this->assertSame('Soal TP yang akan dihapus permanen.', data_get($log->metadata, 'soal'));
    }

    public function test_force_delete_removes_row_and_writes_audit_for_tm(): void
    {
        $this->seedRolesAndPermissions();
        $assistant = $this->actingAsAssistantWithRole(self::HARD_DELETE_ROLE);

        $modul = $this->createModul();
        $soal = $this->createSoalTm($modul, 'Soal TM yang akan dihapus permanen.');
        $soalId = $soal->id;

        $response = $this->deleteJson("/api-v1/soal-tm/{$soalId}?force=1");

        $response->assertOk();
        $response->assertJsonPath('status', 'success');

        $this->assertNull(SoalMandiri::withTrashed()->find($soalId), 'Baris soal_mandiris harus hilang secara fisik.');
        $this->assertSame(0, DB::table('soal_mandiris')->where('id', $soalId)->count());

        $logs = AuditLog::query()->where('action', 'soal.hard_delete')->get();
        $this->assertCount(1, $logs);

        $log = $logs->first();
        $this->assertSame('soal', $log->subject_type);
        $this->assertSame($soalId, $log->subject_id);
        $this->assertSame('asisten', $log->actor_type);
        $this->assertSame($assistant->id, $log->actor_id);
        $this->assertSame('soal_mandiri', data_get($log->metadata, 'model'));
        $this->assertSame('Soal TM yang akan dihapus permanen.', data_get($log->metadata, 'soal'));
    }

    // ------------------------------------------------------------------
    // B4. tanpa force (atau force bukan '1') -> jalur soft delete
    // ------------------------------------------------------------------

    public function test_force_not_equal_to_one_still_uses_soft_delete_for_tp_and_tm(): void
    {
        $this->seedRolesAndPermissions();
        $this->actingAsAssistantWithRole(self::HARD_DELETE_ROLE);

        $modul = $this->createModul();

        $cases = [
            ['soal-tp', 'soal_tps', $this->createSoalTp($modul)->id, 'truthy'],
            ['soal-tm', 'soal_mandiris', $this->createSoalTm($modul)->id, 'true'],
            ['soal-tp', 'soal_tps', $this->createSoalTp($modul)->id, '0'],
        ];

        foreach ($cases as [$prefix, $table, $id, $forceValue]) {
            $response = $this->deleteJson("/api-v1/{$prefix}/{$id}?force={$forceValue}");

            $response->assertOk();

            $row = DB::table($table)->where('id', $id)->first();
            $this->assertNotNull($row, "Baris {$table} harus tetap ada secara fisik (soft delete).");
            $this->assertNotNull($row->deleted_at, 'deleted_at harus terisi setelah soft delete.');
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'soal.hard_delete')->count());
    }

    public function test_missing_force_query_uses_soft_delete_for_tp_and_tm(): void
    {
        $this->seedRolesAndPermissions();
        $this->actingAsAssistantWithRole(self::HARD_DELETE_ROLE);

        $modul = $this->createModul();

        $soalTp = $this->createSoalTp($modul);
        $soalTm = $this->createSoalTm($modul);

        $this->deleteJson("/api-v1/soal-tp/{$soalTp->id}")->assertOk();
        $this->deleteJson("/api-v1/soal-tm/{$soalTm->id}")->assertOk();

        $rowTp = DB::table('soal_tps')->where('id', $soalTp->id)->first();
        $rowTm = DB::table('soal_mandiris')->where('id', $soalTm->id)->first();

        $this->assertNotNull($rowTp);
        $this->assertNotNull($rowTp->deleted_at);
        $this->assertNotNull($rowTm);
        $this->assertNotNull($rowTm->deleted_at);

        $this->assertSame(0, AuditLog::query()->where('action', 'soal.hard_delete')->count());
    }
}
