<?php

namespace Tests\Feature;

use App\Models\Asisten;
use App\Models\Modul;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers T17: dual-control approval of a kunci jawaban on a version item.
 *
 * POST /api-v1/soal-version-items/{id}/jawaban-standar/approve
 * (route name: approve.jawaban-standar.item)
 */
class JawabanStandarDualControlTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api-v1/soal-version-items/%d/jawaban-standar/approve';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutCsrf();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Happy path
    // ---------------------------------------------------------------------

    public function test_software_different_from_creator_approves_pending_item(): void
    {
        $creator = $this->asistenWithRole('ATC');
        $approver = $this->asistenWithRole('SOFTWARE');
        $item = $this->pendingItem($creator->id);

        $this->actingAs($approver, 'asisten');

        $this->postJson(sprintf(self::URI, $item->id))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $item->id)
            ->assertJsonPath('data.jawaban_standar_status', SoalVersionItem::STATUS_APPROVED)
            ->assertJsonPath('data.jawaban_standar_approved_by', $approver->id);

        $item = $item->fresh();
        $this->assertSame(SoalVersionItem::STATUS_APPROVED, $item->jawaban_standar_status);
        $this->assertSame($approver->id, $item->jawaban_standar_approved_by);
        $this->assertNotNull($item->jawaban_standar_approved_at);
        $this->assertSame($creator->id, $item->jawaban_standar_created_by, 'Pembuat kunci tidak boleh berubah saat approve.');
    }

    public function test_ddc_and_admin_roles_may_approve(): void
    {
        foreach (['DDC', 'ADMIN'] as $roleName) {
            $creator = $this->asistenWithRole('ATC');
            $approver = $this->asistenWithRole($roleName);
            $item = $this->pendingItem($creator->id);

            $this->actingAs($approver, 'asisten');

            $this->postJson(sprintf(self::URI, $item->id))
                ->assertOk()
                ->assertJsonPath('data.jawaban_standar_approved_by', $approver->id);
        }
    }

    // ---------------------------------------------------------------------
    // Audit
    // ---------------------------------------------------------------------

    public function test_approval_writes_audit_log_with_item_subject(): void
    {
        $creator = $this->asistenWithRole('ATC');
        $approver = $this->asistenWithRole('SOFTWARE');
        $item = $this->pendingItem($creator->id);

        $this->actingAs($approver, 'asisten');
        $this->postJson(sprintf(self::URI, $item->id))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'jawaban_standar.approve',
            'subject_type' => 'soal_version_item',
            'subject_id' => $item->id,
            'actor_type' => 'asisten',
            'actor_id' => $approver->id,
        ]);
    }

    // ---------------------------------------------------------------------
    // Forbidden: role
    // ---------------------------------------------------------------------

    public function test_atc_without_approver_role_gets_403(): void
    {
        $creator = $this->asistenWithRole('SOFTWARE');
        $actor = $this->asistenWithRole('ATC', 'manage-soal', 'approve-jawaban-standar');
        $item = $this->pendingItem($creator->id);

        $this->actingAs($actor, 'asisten');

        $this->postJson(sprintf(self::URI, $item->id))
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $item = $item->fresh();
        $this->assertSame(SoalVersionItem::STATUS_PENDING, $item->jawaban_standar_status);
        $this->assertNull($item->jawaban_standar_approved_by);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'jawaban_standar.approve']);
    }

    public function test_actor_without_permission_but_with_approver_role_gets_403(): void
    {
        $creator = $this->asistenWithRole('ATC');
        $actor = $this->asistenWithRole('KORDAS', 'manage-soal');
        $item = $this->pendingItem($creator->id);

        $this->actingAs($actor, 'asisten');

        $this->postJson(sprintf(self::URI, $item->id))
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    // ---------------------------------------------------------------------
    // Forbidden: self approval
    // ---------------------------------------------------------------------

    public function test_creator_cannot_approve_own_kunci_even_with_approver_role(): void
    {
        foreach (['SOFTWARE', 'DDC', 'ADMIN'] as $roleName) {
            $actor = $this->asistenWithRole($roleName);
            $item = $this->pendingItem($actor->id);

            $this->actingAs($actor, 'asisten');

            $this->postJson(sprintf(self::URI, $item->id))
                ->assertForbidden()
                ->assertJsonPath('code', 'SELF_APPROVAL_FORBIDDEN');

            $item = $item->fresh();
            $this->assertSame(SoalVersionItem::STATUS_PENDING, $item->jawaban_standar_status);
            $this->assertNull($item->jawaban_standar_approved_by);
        }
    }

    public function test_self_approval_is_forbidden_when_creator_recorded_by_upsert(): void
    {
        $actor = $this->asistenWithRole('SOFTWARE', 'manage-soal');

        $soal = SoalTp::factory()->for($this->modul())->create(['soal' => 'Soal TP self approval']);
        $item = $this->itemInActiveSet($soal);

        $this->actingAs($actor, 'asisten');
        $this->putJson("/api-v1/soal-tp/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci buatan SOFTWARE',
        ])->assertOk()->assertJsonPath('data.jawaban_standar_created_by', $actor->id);

        $this->postJson(sprintf(self::URI, $item->id))
            ->assertForbidden()
            ->assertJsonPath('code', 'SELF_APPROVAL_FORBIDDEN');

        // A different approver can still approve it.
        $other = $this->asistenWithRole('DDC');
        $this->actingAs($other, 'asisten');
        $this->postJson(sprintf(self::URI, $item->id))
            ->assertOk()
            ->assertJsonPath('data.jawaban_standar_approved_by', $other->id);
    }

    // ---------------------------------------------------------------------
    // Invalid status
    // ---------------------------------------------------------------------

    public function test_rejected_item_cannot_be_approved(): void
    {
        $creator = $this->asistenWithRole('ATC');
        $approver = $this->asistenWithRole('SOFTWARE');
        $item = $this->pendingItem($creator->id, [
            'jawaban_standar_status' => SoalVersionItem::STATUS_REJECTED,
            'reject_reason' => 'Rumus belum lengkap',
        ]);

        $this->actingAs($approver, 'asisten');

        $this->postJson(sprintf(self::URI, $item->id))
            ->assertStatus(409)
            ->assertJsonPath('code', 'INVALID_STATUS');

        $this->assertSame(SoalVersionItem::STATUS_REJECTED, $item->fresh()->jawaban_standar_status);
    }

    public function test_approving_twice_returns_409(): void
    {
        $creator = $this->asistenWithRole('ATC');
        $approver = $this->asistenWithRole('SOFTWARE');
        $item = $this->pendingItem($creator->id);

        $this->actingAs($approver, 'asisten');

        $this->postJson(sprintf(self::URI, $item->id))->assertOk();
        $this->postJson(sprintf(self::URI, $item->id))
            ->assertStatus(409)
            ->assertJsonPath('code', 'INVALID_STATUS');

        $this->assertSame(1, \App\Models\AuditLog::query()
            ->where('action', 'jawaban_standar.approve')
            ->where('subject_id', $item->id)
            ->count());
    }

    // ---------------------------------------------------------------------
    // Routing / authentication
    // ---------------------------------------------------------------------

    public function test_route_is_registered_with_asisten_guard(): void
    {
        $route = app('router')->getRoutes()->getByName('approve.jawaban-standar.item');

        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertSame('/api-v1/soal-version-items/{id}/jawaban-standar/approve', '/'.ltrim($route->uri(), '/'));
        $this->assertContains('auth:asisten', $route->gatherMiddleware());
    }

    public function test_guest_gets_401(): void
    {
        $item = $this->pendingItem($this->asistenWithRole('ATC')->id);

        auth('asisten')->logout();

        $this->postJson(sprintf(self::URI, $item->id))->assertUnauthorized();
    }

    public function test_unknown_item_returns_404(): void
    {
        $this->actingAs($this->asistenWithRole('SOFTWARE'), 'asisten');

        $this->postJson(sprintf(self::URI, 999999))->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Create an assistant whose primary role is $roleName and, via Spatie,
     * receives the given permissions on that role.
     */
    private function asistenWithRole(string $roleName, string ...$permissions): Asisten
    {
        // asistens.role_id is a foreign key to roles.id.
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'asisten']);

        foreach ($permissions as $permissionName) {
            $permission = Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'asisten']);
            $role->givePermissionTo($permission);
        }

        $asisten = Asisten::factory()->create(['role_id' => $role->id]);
        // permission:* middleware reads Spatie roles, not asistens.role_id.
        $asisten->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $asisten;
    }

    private function modul(): Modul
    {
        return Modul::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pendingItem(?int $createdBy = null, array $attributes = []): SoalVersionItem
    {
        $modul = $this->modul();
        $soal = SoalTp::factory()->for($modul)->create(['soal' => 'Soal TP dual control']);

        return $this->itemInActiveSet($soal, array_merge([
            'jawaban_standar' => 'Kunci menunggu persetujuan',
            'jawaban_standar_status' => SoalVersionItem::STATUS_PENDING,
            'jawaban_standar_created_by' => $createdBy,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function itemInActiveSet(SoalTp $soal, array $attributes = []): SoalVersionItem
    {
        $set = SoalVersionSet::create([
            'modul_id' => $soal->modul_id,
            'version_no' => 1,
            'activated_at' => now(),
        ]);

        return SoalVersionItem::create(array_merge([
            'version_set_id' => $set->id,
            'soal_type' => SoalVersionItem::SOAL_TP,
            'soal_id' => $soal->id,
            'bobot' => 100,
            'max_score' => 100,
            'teks_snapshot' => $soal->soal,
        ], $attributes))->fresh();
    }
}
