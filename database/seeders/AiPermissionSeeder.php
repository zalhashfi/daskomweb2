<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AiPermissionSeeder extends Seeder
{
    /**
     * Guard yang dipakai seluruh permission & role asisten.
     */
    private const GUARD = 'asisten';

    /**
     * Daftar permission baru untuk fitur AI.
     *
     * @var array<int, string>
     */
    public const PERMISSIONS = [
        'manage-ai-gate',
        'manage-ai-budget',
        'approve-jawaban-standar',
        'approve-modul-documents',
        'manage-modul-documents',
        'regrade-ai',
        'unlock-jawaban-submission',
        'view-ai-metrics',
    ];

    /**
     * Matriks role => permission AI yang diberikan.
     *
     * @var array<string, array<int, string>>
     */
    public const MATRIX = [
        'SOFTWARE' => [
            'manage-ai-gate',
            'manage-ai-budget',
            'approve-jawaban-standar',
            'approve-modul-documents',
            'manage-modul-documents',
            'regrade-ai',
            'unlock-jawaban-submission',
            'view-ai-metrics',
        ],
        'DDC' => [
            'manage-ai-gate',
            'manage-ai-budget',
            'approve-jawaban-standar',
            'approve-modul-documents',
            'manage-modul-documents',
            'view-ai-metrics',
        ],
        'KORDAS' => [
            'regrade-ai',
            'unlock-jawaban-submission',
            'view-ai-metrics',
        ],
        'KOORPRAK' => [
            'regrade-ai',
            'unlock-jawaban-submission',
            'view-ai-metrics',
        ],
        'ATC' => [
            'manage-modul-documents',
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Bersihkan cache Spatie agar tidak memakai data basi.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Buat permission AI (idempotent: tidak menduplikasi).
        foreach (self::PERMISSIONS as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => self::GUARD,
            ]);
        }

        // Berikan permission ke role sesuai matriks.
        foreach (self::MATRIX as $roleName => $permissionNames) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => self::GUARD,
            ]);

            // givePermissionTo() hanya attach yang belum dimiliki -> aman diulang.
            $role->givePermissionTo($permissionNames);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
