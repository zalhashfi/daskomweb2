<?php

namespace Tests\Feature;

use App\Models\Asisten;
use App\Models\Modul;
use App\Models\SoalMandiri;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use App\Services\Soal\BobotValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers T15: bobot validation on approve, reject reason length, and
 * createVersion cloning (kunci must go back to pending).
 */
class SoalVersionSetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Every API call needs an asisten; the default one holds manage-soal.
        $this->actingAsAsistenNamed('soal-version-default-tester', 'manage-soal');
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // BobotValidator
    // ---------------------------------------------------------------------

    public function test_validator_sums_bobot_per_type_and_ignores_empty_types(): void
    {
        $set = $this->makeSet($this->modul(), 1);
        $this->makeItems($set, SoalVersionItem::SOAL_TP, [60, 40]);

        $result = app(BobotValidator::class)->validate($set);

        $this->assertTrue($result['ok']);
        $this->assertSame(['tp' => 100, 'tm' => 0], $result['per_type']);
    }

    public function test_validator_reports_empty_set_as_valid_with_zero_totals(): void
    {
        $set = $this->makeSet($this->modul(), 1);

        $result = app(BobotValidator::class)->validate($set);

        $this->assertTrue($result['ok'], 'Set tanpa item tidak boleh divalidasi.');
        $this->assertSame(['tp' => 0, 'tm' => 0], $result['per_type']);
    }

    // ---------------------------------------------------------------------
    // approve
    // ---------------------------------------------------------------------

    public function test_approve_with_tp_total_95_returns_422_bobot_tidak_100(): void
    {
        $set = $this->makeSet($this->modul(), 1);
        $this->makeItems($set, SoalVersionItem::SOAL_TP, [60, 35]);

        $response = $this->postJson("/api-v1/soal-version-sets/{$set->id}/approve");

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'BOBOT_TIDAK_100')
            ->assertJsonPath('per_type.tp', 95);

        $this->assertNull($set->fresh()->activated_at, 'Set gagal validasi tidak boleh aktif.');
        $this->assertSame(SoalVersionSet::STATUS_DRAFT, $set->fresh()->status);
    }

    public function test_approve_with_tp_total_100_activates_and_deactivates_previous_version(): void
    {
        $modul = $this->modul();

        $previous = $this->makeSet($modul, 1, [
            'status' => SoalVersionSet::STATUS_APPROVED,
            'activated_at' => now()->subDay(),
        ]);
        $this->makeItems($previous, SoalVersionItem::SOAL_TP, [100]);

        $current = $this->makeSet($modul, 2);
        $this->makeItems($current, SoalVersionItem::SOAL_TP, [50, 50]);

        $this->postJson("/api-v1/soal-version-sets/{$current->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $current = $current->fresh();
        $this->assertNotNull($current->activated_at, 'Set yang lolos harus activated_at terisi.');
        $this->assertSame(SoalVersionSet::STATUS_APPROVED, $current->status);
        $this->assertSame('asisten', $current->approved_by_type);

        $this->assertNull(
            $previous->fresh()->activated_at,
            'Versi aktif sebelumnya di modul yang sama harus dinonaktifkan.'
        );
        $this->assertSame(1, SoalVersionSet::active()->forModul($modul->id)->count());
    }

    public function test_approve_does_not_deactivate_active_set_of_another_modul(): void
    {
        $otherModul = $this->modul();
        $otherActive = $this->makeSet($otherModul, 1, ['activated_at' => now()]);

        $modul = $this->modul();
        $set = $this->makeSet($modul, 1);
        $this->makeItems($set, SoalVersionItem::SOAL_TP, [100]);

        $this->postJson("/api-v1/soal-version-sets/{$set->id}/approve")->assertOk();

        $this->assertNotNull($otherActive->fresh()->activated_at);
    }

    public function test_item_with_bobot_zero_is_still_valid_when_total_is_100(): void
    {
        $set = $this->makeSet($this->modul(), 1);
        $this->makeItems($set, SoalVersionItem::SOAL_TP, [0, 100]);

        $response = $this->postJson("/api-v1/soal-version-sets/{$set->id}/approve");

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertNotNull($set->fresh()->activated_at);

        $zero = $set->items()->where('bobot', 0)->first();
        $this->assertNotNull($zero, 'Item bobot 0 harus tetap tersimpan.');
    }

    public function test_approve_validates_each_type_with_items_independently(): void
    {
        $set = $this->makeSet($this->modul(), 1);
        $this->makeItems($set, SoalVersionItem::SOAL_TP, [100]);
        $this->makeItems($set, SoalVersionItem::SOAL_TM, [40, 40]);

        $response = $this->postJson("/api-v1/soal-version-sets/{$set->id}/approve");

        $response->assertStatus(422)
            ->assertJsonPath('code', 'BOBOT_TIDAK_100')
            ->assertJsonPath('per_type.tp', 100)
            ->assertJsonPath('per_type.tm', 80);
    }

    public function test_approve_unknown_set_returns_404(): void
    {
        $this->postJson('/api-v1/soal-version-sets/999999/approve')->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // createVersion
    // ---------------------------------------------------------------------

    public function test_create_version_from_active_set_copies_items_and_resets_kunci_status(): void
    {
        $modul = $this->modul();

        $active = $this->makeSet($modul, 1, [
            'status' => SoalVersionSet::STATUS_APPROVED,
            'activated_at' => now(),
        ]);

        SoalVersionItem::create([
            'version_set_id' => $active->id,
            'soal_type' => SoalVersionItem::SOAL_TP,
            'soal_id' => 501,
            'bobot' => 40,
            'max_score' => 90,
            'teks_snapshot' => 'Teks soal TP satu',
            'jawaban_standar' => 'Kunci TP satu',
            'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
            'jawaban_standar_approved_by' => 1,
            'jawaban_standar_approved_at' => now(),
        ]);

        SoalVersionItem::create([
            'version_set_id' => $active->id,
            'soal_type' => SoalVersionItem::SOAL_TM,
            'soal_id' => 601,
            'bobot' => 60,
            'max_score' => 100,
            'teks_snapshot' => 'Teks soal TM satu',
            'jawaban_standar' => 'Kunci TM satu',
            'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
            'jawaban_standar_approved_by' => 1,
            'jawaban_standar_approved_at' => now(),
        ]);

        $response = $this->postJson("/api-v1/soal-version-sets/{$active->id}/versions");

        $response->assertOk()->assertJsonPath('status', 'success');

        $newSet = SoalVersionSet::query()
            ->forModul($modul->id)
            ->whereKeyNot($active->id)
            ->firstOrFail();

        $this->assertSame(2, $newSet->version_no, 'version_no harus naik 1.');
        $this->assertSame(SoalVersionSet::STATUS_DRAFT, $newSet->status);
        $this->assertNull($newSet->activated_at);
        $this->assertNull($newSet->approved_by_id);
        $this->assertNull($newSet->approved_by_type);
        $this->assertSame(2, $newSet->items()->count(), 'Jumlah item harus sama.');

        $tp = $newSet->items()->where('soal_type', SoalVersionItem::SOAL_TP)->firstOrFail();
        $this->assertSame(501, $tp->soal_id);
        $this->assertSame('Teks soal TP satu', $tp->teks_snapshot);
        $this->assertSame('Kunci TP satu', $tp->jawaban_standar);
        $this->assertSame(40, $tp->bobot);
        $this->assertSame(90, $tp->max_score);
        $this->assertSame(SoalVersionItem::STATUS_PENDING, $tp->jawaban_standar_status);
        $this->assertNull($tp->jawaban_standar_approved_by);
        $this->assertNull($tp->jawaban_standar_approved_at);

        $tm = $newSet->items()->where('soal_type', SoalVersionItem::SOAL_TM)->firstOrFail();
        $this->assertSame('Teks soal TM satu', $tm->teks_snapshot);
        $this->assertSame('Kunci TM satu', $tm->jawaban_standar);
        $this->assertSame(60, $tm->bobot);
        $this->assertSame(SoalVersionItem::STATUS_PENDING, $tm->jawaban_standar_status);

        // The source set must remain untouched and still active.
        $this->assertSame(SoalVersionItem::STATUS_APPROVED, $active->items()->first()->jawaban_standar_status);
        $this->assertNotNull($active->fresh()->activated_at);
    }

    public function test_create_version_without_active_set_bootstraps_from_modul_questions_with_bobot_zero(): void
    {
        $modul = $this->modul();

        $tp = SoalTp::factory()->for($modul)->create(['soal' => 'Soal TP asli']);
        $tm = SoalMandiri::factory()->for($modul)->create(['soal' => 'Soal TM asli']);
        // Soal pada modul lain dan soal yang di-soft-delete tidak boleh ikut.
        SoalTp::factory()->for($this->modul())->create(['soal' => 'Soal modul lain']);
        $deleted = SoalTp::factory()->for($modul)->create(['soal' => 'Soal dihapus']);
        $deleted->delete();

        $response = $this->postJson("/api-v1/modul/{$modul->id}/soal-version-sets");

        $response->assertOk();

        $set = SoalVersionSet::query()->forModul($modul->id)->sole();

        $this->assertSame(1, $set->version_no);
        $this->assertSame(SoalVersionSet::STATUS_DRAFT, $set->status);
        $this->assertNull($set->activated_at);
        $this->assertSame(2, $set->items()->count());

        $this->assertDatabaseHas('soal_version_items', [
            'version_set_id' => $set->id,
            'soal_type' => SoalVersionItem::SOAL_TP,
            'soal_id' => $tp->id,
            'bobot' => 0,
            'teks_snapshot' => 'Soal TP asli',
            'jawaban_standar_status' => SoalVersionItem::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('soal_version_items', [
            'version_set_id' => $set->id,
            'soal_type' => SoalVersionItem::SOAL_TM,
            'soal_id' => $tm->id,
            'bobot' => 0,
            'teks_snapshot' => 'Soal TM asli',
        ]);

        $this->assertDatabaseMissing('soal_version_items', [
            'version_set_id' => $set->id,
            'soal_id' => $deleted->id,
        ]);
    }

    public function test_create_version_increments_from_highest_version_even_if_not_active(): void
    {
        $modul = $this->modul();

        $active = $this->makeSet($modul, 1, ['activated_at' => now()]);
        $this->makeItems($active, SoalVersionItem::SOAL_TP, [100]);

        // A newer draft already exists but is not active.
        $this->makeSet($modul, 2);

        $this->postJson("/api-v1/soal-version-sets/{$active->id}/versions")->assertOk();

        $newSet = SoalVersionSet::query()->forModul($modul->id)->where('version_no', 3)->first();

        $this->assertNotNull($newSet, 'version_no baru harus max(version_no) + 1.');
        $this->assertSame(1, $newSet->items()->count(), 'Sumber salinan adalah set aktif terakhir.');
    }

    // ---------------------------------------------------------------------
    // reject
    // ---------------------------------------------------------------------

    public function test_reject_with_short_reason_returns_422(): void
    {
        $set = $this->makeSet($this->modul(), 1);

        $response = $this->postJson("/api-v1/soal-version-sets/{$set->id}/reject", [
            'reason' => 'salah',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'REASON_TOO_SHORT');

        $this->assertSame(SoalVersionSet::STATUS_DRAFT, $set->fresh()->status);
        $this->assertNull($set->fresh()->reject_reason);
    }

    public function test_reject_without_reason_returns_422(): void
    {
        $set = $this->makeSet($this->modul(), 1);

        $this->postJson("/api-v1/soal-version-sets/{$set->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'REASON_TOO_SHORT');
    }

    public function test_reject_with_valid_reason_persists_reason_and_status(): void
    {
        $set = $this->makeSet($this->modul(), 1);

        $reason = 'Bobot soal tidak sesuai panduan modul.';

        $this->postJson("/api-v1/soal-version-sets/{$set->id}/reject", ['reason' => $reason])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $set = $set->fresh();
        $this->assertSame(SoalVersionSet::STATUS_REJECTED, $set->status);
        $this->assertSame($reason, $set->reject_reason);
    }

    public function test_reject_with_exactly_ten_characters_is_accepted(): void
    {
        $set = $this->makeSet($this->modul(), 1);

        $this->postJson("/api-v1/soal-version-sets/{$set->id}/reject", [
            'reason' => '1234567890',
        ])->assertOk();

        $this->assertSame('1234567890', $set->fresh()->reject_reason);
    }

    // ---------------------------------------------------------------------
    // Routing / authorization
    // ---------------------------------------------------------------------

    public function test_routes_reject_guests(): void
    {
        $set = $this->makeSet($this->modul(), 1);

        // Real middleware: no session => not authenticated on the asisten guard.
        auth('asisten')->logout();

        $this->postJson("/api-v1/soal-version-sets/{$set->id}/approve")->assertUnauthorized();
        $this->postJson("/api-v1/soal-version-sets/{$set->id}/reject", ['reason' => 'alasan panjang sekali'])
            ->assertUnauthorized();
        $this->postJson("/api-v1/soal-version-sets/{$set->id}/versions")->assertUnauthorized();
    }

    public function test_route_definition_uses_asisten_auth_and_permission(): void
    {
        $routes = app('router')->getRoutes();

        foreach ([
            'approve.soal-version-set',
            'reject.soal-version-set',
            'create-version.soal-version-set',
            'create-version-by-modul.soal-version-set',
        ] as $name) {
            $route = $routes->getByName($name);
            $this->assertNotNull($route, "Route {$name} harus terdaftar.");
            $this->assertContains('auth:asisten', $route->gatherMiddleware(), "{$name} harus memakai auth:asisten.");
            $this->assertContains(
                'permission:approve-jawaban-standar|manage-soal',
                $route->gatherMiddleware(),
                "{$name} harus memakai permission approve-jawaban-standar|manage-soal."
            );
        }
    }

    public function test_asisten_without_permission_gets_403(): void
    {
        $this->actingAsAsistenNamed('soal-version-tanpa-permission', 'view-ai-metrics');

        $set = $this->makeSet($this->modul(), 1);

        $this->postJson("/api-v1/soal-version-sets/{$set->id}/approve")->assertForbidden();
        $this->postJson("/api-v1/soal-version-sets/{$set->id}/versions")->assertForbidden();
    }

    public function test_asisten_with_manage_soal_can_approve_and_is_recorded(): void
    {
        $asisten = auth('asisten')->user();

        $set = $this->makeSet($this->modul(), 1);
        $this->makeItems($set, SoalVersionItem::SOAL_TP, [100]);

        $this->postJson("/api-v1/soal-version-sets/{$set->id}/approve")->assertOk();

        $set = $set->fresh();
        $this->assertNotNull($set->activated_at);
        $this->assertSame($asisten->id, $set->approved_by_id);
        $this->assertSame('asisten', $set->approved_by_type);
    }

    public function test_asisten_with_approve_jawaban_standar_can_approve(): void
    {
        $this->actingAsAsistenNamed('soal-version-approver', 'approve-jawaban-standar');

        $set = $this->makeSet($this->modul(), 1);
        $this->makeItems($set, SoalVersionItem::SOAL_TP, [100]);

        $this->postJson("/api-v1/soal-version-sets/{$set->id}/approve")->assertOk();
        $this->assertNotNull($set->fresh()->activated_at);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Create an assistant holding exactly the given permission.
     */
    private function actingAsAsistenNamed(string $roleName, string $permissionName): Asisten
    {
        $role = Role::firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'asisten',
        ]);

        $permission = Permission::firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'asisten',
        ]);
        $role->givePermissionTo($permission);

        $asisten = Asisten::factory()->create(['role_id' => $role->id]);
        $asisten->assignRole($role);

        $this->actingAs($asisten, 'asisten');

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
    private function makeSet(Modul $modul, int $versionNo, array $attributes = []): SoalVersionSet
    {
        return SoalVersionSet::create(array_merge([
            'modul_id' => $modul->id,
            'version_no' => $versionNo,
        ], $attributes))->fresh();
    }

    /**
     * @param  list<int>  $bobot
     */
    private function makeItems(SoalVersionSet $set, string $soalType, array $bobot): void
    {
        foreach (array_values($bobot) as $index => $weight) {
            SoalVersionItem::create([
                'version_set_id' => $set->id,
                'soal_type' => $soalType,
                'soal_id' => $set->id * 1000 + $index,
                'bobot' => $weight,
                'max_score' => 100,
                'teks_snapshot' => 'Soal '.strtoupper($soalType).' '.($index + 1),
            ]);
        }
    }
}
