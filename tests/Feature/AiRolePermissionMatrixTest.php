<?php

namespace Tests\Feature;

use Database\Seeders\AiPermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AiRolePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, string>
     */
    private const AI_PERMISSIONS = [
        'manage-ai-gate',
        'manage-ai-budget',
        'approve-jawaban-standar',
        'approve-modul-documents',
        'manage-modul-documents',
        'regrade-ai',
        'unlock-jawaban-submission',
        'view-ai-metrics',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Mulai dari kondisi bersih agar cache Spatie tidak memakai data lama.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Jalankan seeder dengan cache Spatie yang sudah dibersihkan.
     */
    private function runSeeder(string $seederClass): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed($seederClass);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function role(string $name): Role
    {
        return Role::where('name', $name)->where('guard_name', 'asisten')->firstOrFail();
    }

    private function assertRoleHas(string $role, array $permissions): void
    {
        $roleModel = $this->role($role);

        $missing = collect($permissions)
            ->reject(fn (string $permission) => $roleModel->hasPermissionTo($permission))
            ->values()
            ->all();

        $this->assertSame([], $missing, "Role {$role} tidak memegang permission: ".implode(', ', $missing));
    }

    private function assertRoleHasNot(string $role, array $permissions): void
    {
        $roleModel = $this->role($role);

        foreach ($permissions as $permission) {
            $this->assertFalse(
                $roleModel->hasPermissionTo($permission),
                "Role {$role} seharusnya TIDAK memegang permission {$permission}"
            );
        }
    }

    public function test_ai_permission_seeder_is_idempotent_and_keeps_old_permissions(): void
    {
        // 1) Seed permission lama lebih dulu, lalu snapshot namanya.
        $this->runSeeder(RolePermissionsSeeder::class);

        $oldPermissionNames = Permission::query()
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $oldPermissionCount = Permission::query()->count();

        $this->assertNotEmpty($oldPermissionNames, 'Snapshot permission lama tidak boleh kosong.');
        $this->assertSame(41, $oldPermissionCount, 'RolePermissionsSeeder seharusnya membuat 41 permission (32 asisten + 9 praktikan).');

        // 2) Jalankan seeder AI.
        $this->runSeeder(AiPermissionSeeder::class);

        $aiPermissionCountAfterFirstRun = Permission::query()
            ->where('guard_name', 'asisten')
            ->whereIn('name', self::AI_PERMISSIONS)
            ->count();

        $countAfterFirstRun = Permission::query()->count();

        $this->assertSame(8, $aiPermissionCountAfterFirstRun, 'Seeder AI seharusnya membuat tepat 8 permission.');
        $this->assertSame($oldPermissionCount + 8, $countAfterFirstRun, 'Total permission harus bertambah tepat 8.');
        $this->assertSame(count($oldPermissionNames) + 8, $countAfterFirstRun);

        // Semua permission baru memakai guard asisten.
        $this->assertSame(0, Permission::query()
            ->whereIn('name', self::AI_PERMISSIONS)
            ->where('guard_name', '!=', 'asisten')
            ->count());

        // 3) Jalankan seeder AI lagi: tidak boleh error / menduplikasi.
        $this->runSeeder(AiPermissionSeeder::class);

        $this->assertSame(8, Permission::query()
            ->where('guard_name', 'asisten')
            ->whereIn('name', self::AI_PERMISSIONS)
            ->count(), 'Permission AI tidak boleh terduplikasi setelah seeder dijalankan dua kali.');

        $this->assertSame(
            $countAfterFirstRun,
            Permission::query()->count(),
            'Jumlah total permission tidak boleh berubah pada run kedua.'
        );

        // 4) Tidak ada permission lama yang hilang.
        $remainingNames = Permission::query()->pluck('name')->all();
        $missing = array_values(array_diff($oldPermissionNames, $remainingNames));

        $this->assertSame([], $missing, 'Permission lama hilang: '.implode(', ', $missing));

        // Permission lama tetap terpasang pada modul/praktikan seperti semula.
        $allNames = Permission::query()->orderBy('name')->pluck('name')->all();
        $this->assertSame(
            collect(array_merge($oldPermissionNames, self::AI_PERMISSIONS))->unique()->sort()->values()->all(),
            collect($allNames)->unique()->sort()->values()->all(),
            'Daftar permission akhir harus = permission lama + 8 permission AI.'
        );

        $this->assertSame(
            49,
            Permission::query()->count(),
            'Total permission akhir harus 49 (41 lama + 8 AI), tidak ada baris lama yang hilang.'
        );

        // 5) Pivot role_has_permissions tidak berisi duplikat.
        $duplicatePivots = DB::table(config('permission.table_names.role_has_permissions'))
            ->select('role_id', 'permission_id')
            ->groupBy('role_id', 'permission_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $this->assertSame(0, $duplicatePivots, 'Tabel role_has_permissions tidak boleh punya duplikat.');
    }

    public function test_software_and_ddc_hold_manage_ai_gate_and_budget(): void
    {
        $this->runSeeder(AiPermissionSeeder::class);

        foreach (['SOFTWARE', 'DDC'] as $role) {
            $this->assertRoleHas($role, ['manage-ai-gate', 'manage-ai-budget']);
        }

        $this->assertRoleHas('SOFTWARE', self::AI_PERMISSIONS);

        $this->assertRoleHas('DDC', [
            'approve-jawaban-standar',
            'approve-modul-documents',
            'manage-modul-documents',
            'view-ai-metrics',
        ]);
    }

    public function test_kordas_and_koorprak_have_view_metrics_but_not_gate_nor_budget(): void
    {
        $this->runSeeder(AiPermissionSeeder::class);

        foreach (['KORDAS', 'KOORPRAK'] as $role) {
            $this->assertRoleHas($role, ['view-ai-metrics', 'regrade-ai', 'unlock-jawaban-submission']);
            $this->assertRoleHasNot($role, ['manage-ai-gate', 'manage-ai-budget']);
        }
    }

    public function test_atc_has_modul_documents_but_not_gate_nor_approve_standar(): void
    {
        $this->runSeeder(AiPermissionSeeder::class);

        $this->assertRoleHas('ATC', ['manage-modul-documents']);
        $this->assertRoleHasNot('ATC', ['manage-ai-gate', 'approve-jawaban-standar']);
    }

    public function test_roles_created_when_missing(): void
    {
        // Tanpa RolePermissionsSeeder: role belum ada, seeder wajib membuatnya.
        $this->assertSame(0, Role::query()->count());

        $this->runSeeder(AiPermissionSeeder::class);

        foreach (array_keys(AiPermissionSeeder::MATRIX) as $roleName) {
            $this->assertTrue(
                Role::where('name', $roleName)->where('guard_name', 'asisten')->exists(),
                "Role {$roleName} seharusnya dibuat oleh seeder."
            );
        }
    }
}
