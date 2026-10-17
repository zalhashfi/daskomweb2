<?php

namespace Tests\Feature;

use App\Models\Asisten;
use App\Models\JawabanMandiri;
use App\Models\JawabanTp;
use App\Models\JawabanSubmission;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalMandiri;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers T16: ATC (manage-soal) writes a kunci onto the item of the active
 * version set; the item always goes back to `pending` with approvals cleared.
 *
 * Dual-control approve (T17) is intentionally out of scope here.
 */
class JawabanStandarApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAsAsistenNamed('jawaban-standar-tester', 'manage-soal');
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Happy path
    // ---------------------------------------------------------------------

    public function test_saving_kunci_stores_text_and_marks_item_pending(): void
    {
        $soal = SoalTp::factory()->for($this->modul())->create(['soal' => 'Soal TP aktif']);
        $item = $this->itemInActiveSet($soal, SoalVersionItem::SOAL_TP, ['bobot' => 30]);

        $response = $this->putJson("/api-v1/soal-tp/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Energi kinetik adalah 1/2 mv^2',
            'bobot' => 40,
            'max_score' => 90,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $item->id)
            ->assertJsonPath('data.jawaban_standar_status', SoalVersionItem::STATUS_PENDING)
            ->assertJsonPath('data.jawaban_standar', 'Energi kinetik adalah 1/2 mv^2');

        $this->assertDatabaseHas('soal_version_items', [
            'id' => $item->id,
            'jawaban_standar' => 'Energi kinetik adalah 1/2 mv^2',
            'jawaban_standar_status' => SoalVersionItem::STATUS_PENDING,
            'bobot' => 40,
            'max_score' => 90,
        ]);
    }

    public function test_kunci_without_optional_fields_keeps_existing_bobot_and_max_score(): void
    {
        $soal = SoalMandiri::factory()->for($this->modul())->create(['soal' => 'Soal TM aktif']);
        $item = $this->itemInActiveSet($soal, SoalVersionItem::SOAL_TM, ['bobot' => 60, 'max_score' => 75]);

        $this->putJson("/api-v1/soal-tm/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci TM tanpa bobot',
        ])->assertOk()->assertJsonPath('data.id', $item->id);

        $item = $item->fresh();
        $this->assertSame('Kunci TM tanpa bobot', $item->jawaban_standar);
        $this->assertSame(60, $item->bobot, 'bobot tidak dikirim tidak boleh berubah.');
        $this->assertSame(75, $item->max_score, 'max_score tidak dikirim tidak boleh berubah.');
    }

    public function test_kunci_is_written_to_active_set_not_inactive_one(): void
    {
        $modul = $this->modul();
        $soal = SoalTp::factory()->for($modul)->create(['soal' => 'Soal TP dua versi']);

        $old = $this->makeSet($modul, 1, [
            'status' => SoalVersionSet::STATUS_APPROVED,
            'activated_at' => now()->subDay(),
        ]);
        $oldItem = $this->makeItem($old, SoalVersionItem::SOAL_TP, $soal->id);

        $active = $this->makeSet($modul, 2, [
            'status' => SoalVersionSet::STATUS_APPROVED,
            'activated_at' => now(),
        ]);
        $activeItem = $this->makeItem($active, SoalVersionItem::SOAL_TP, $soal->id);

        $this->putJson("/api-v1/soal-tp/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci untuk versi aktif',
        ])->assertOk()->assertJsonPath('data.id', $activeItem->id);

        $this->assertSame('Kunci untuk versi aktif', $activeItem->fresh()->jawaban_standar);
        $this->assertNull($oldItem->fresh()->jawaban_standar, 'Item versi tidak aktif tidak boleh diubah.');
    }

    // ---------------------------------------------------------------------
    // Re-saving an approved kunci
    // ---------------------------------------------------------------------

    public function test_resaving_approved_kunci_resets_status_and_clears_approval(): void
    {
        $approver = Asisten::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'root', 'guard_name' => 'asisten'])->id]);
        $soal = SoalTp::factory()->for($this->modul())->create(['soal' => 'Soal TP sudah disetujui']);

        $item = $this->itemInActiveSet($soal, SoalVersionItem::SOAL_TP, [
            'jawaban_standar' => 'Kunci lama',
            'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
            'jawaban_standar_approved_by' => $approver->id,
            'jawaban_standar_approved_at' => now()->subHour(),
            'reject_reason' => null,
        ]);

        $this->putJson("/api-v1/soal-tp/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci hasil revisi',
        ])->assertOk()->assertJsonPath('data.jawaban_standar_status', SoalVersionItem::STATUS_PENDING);

        $item = $item->fresh();
        $this->assertSame('Kunci hasil revisi', $item->jawaban_standar);
        $this->assertSame(SoalVersionItem::STATUS_PENDING, $item->jawaban_standar_status);
        $this->assertNull($item->jawaban_standar_approved_by);
        $this->assertNull($item->jawaban_standar_approved_at);
        $this->assertNull($item->reject_reason);

        $this->assertDatabaseHas('soal_version_items', [
            'id' => $item->id,
            'jawaban_standar_status' => SoalVersionItem::STATUS_PENDING,
            'jawaban_standar_approved_by' => null,
            'jawaban_standar_approved_at' => null,
        ]);
    }

    public function test_resaving_rejected_kunci_clears_reject_reason(): void
    {
        $soal = SoalMandiri::factory()->for($this->modul())->create(['soal' => 'Soal TM ditolak']);

        $item = $this->itemInActiveSet($soal, SoalVersionItem::SOAL_TM, [
            'jawaban_standar' => 'Kunci ditolak',
            'jawaban_standar_status' => SoalVersionItem::STATUS_REJECTED,
            'reject_reason' => 'Rumus belum lengkap',
        ]);

        $this->putJson("/api-v1/soal-tm/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci perbaikan',
        ])->assertOk();

        $item = $item->fresh();
        $this->assertSame(SoalVersionItem::STATUS_PENDING, $item->jawaban_standar_status);
        $this->assertNull($item->reject_reason);
    }

    // ---------------------------------------------------------------------
    // Guard: soal sudah punya jawaban
    // ---------------------------------------------------------------------

    public function test_soal_with_answers_returns_409_and_changes_nothing(): void
    {
        $soal = SoalTp::factory()->for($this->modul())->create(['soal' => 'Soal TP sudah dijawab']);
        $item = $this->itemInActiveSet($soal, SoalVersionItem::SOAL_TP, [
            'jawaban_standar' => 'Kunci awal',
            'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
        ]);

        $praktikan = Praktikan::factory()->create();
        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $soal->modul_id,
            'tipe' => JawabanSubmission::TIPE_TP,
            'attempt_no' => 1,
        ]);

        JawabanTp::create([
            'submission_id' => $submission->id,
            'praktikan_id' => $praktikan->id,
            'soal_id' => $soal->id,
            'modul_id' => $soal->modul_id,
            'jawaban' => 'Jawaban praktikan',
        ]);

        $response = $this->putJson("/api-v1/soal-tp/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci baru',
            'bobot' => 10,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'SOAL_HAS_ANSWERS')
            ->assertJsonPath('count', 1);

        $item = $item->fresh();
        $this->assertSame('Kunci awal', $item->jawaban_standar);
        $this->assertSame(SoalVersionItem::STATUS_APPROVED, $item->jawaban_standar_status);
        $this->assertSame(0, $item->bobot);
    }

    public function test_mandiri_soal_with_answers_returns_409(): void
    {
        $soal = SoalMandiri::factory()->for($this->modul())->create(['soal' => 'Soal TM dijawab']);
        $this->itemInActiveSet($soal, SoalVersionItem::SOAL_TM);

        $praktikan = Praktikan::factory()->create();
        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $soal->modul_id,
            'tipe' => JawabanSubmission::TIPE_TM,
            'attempt_no' => 1,
        ]);

        JawabanMandiri::create([
            'submission_id' => $submission->id,
            'praktikan_id' => $praktikan->id,
            'soal_id' => $soal->id,
            'modul_id' => $soal->modul_id,
            'jawaban' => 'Jawaban praktikan TM',
        ]);

        $this->putJson("/api-v1/soal-tm/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci baru TM',
        ])->assertStatus(409)->assertJsonPath('code', 'SOAL_HAS_ANSWERS');
    }

    // ---------------------------------------------------------------------
    // Guard: bobot
    // ---------------------------------------------------------------------

    public function test_bobot_pushing_total_over_100_returns_422(): void
    {
        $modul = $this->modul();
        $set = $this->makeSet($modul, 1, ['activated_at' => now()]);

        $first = SoalTp::factory()->for($modul)->create(['soal' => 'Soal TP pertama']);
        $second = SoalTp::factory()->for($modul)->create(['soal' => 'Soal TP kedua']);

        $firstItem = $this->makeItem($set, SoalVersionItem::SOAL_TP, $first->id, ['bobot' => 60]);
        $secondItem = $this->makeItem($set, SoalVersionItem::SOAL_TP, $second->id, ['bobot' => 40]);

        $response = $this->putJson("/api-v1/soal-tp/{$second->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci kedua',
            'bobot' => 50,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'BOBOT_TIDAK_100')
            ->assertJsonPath('per_type.tp', 110);

        $secondItem = $secondItem->fresh();
        $this->assertNull($secondItem->jawaban_standar, 'Item tidak boleh tersimpan saat bobot ditolak.');
        $this->assertSame(40, $secondItem->bobot);
        $this->assertSame(60, $firstItem->fresh()->bobot);
    }

    public function test_changing_kunci_without_touching_bobot_keeps_totals_valid(): void
    {
        $modul = $this->modul();
        $set = $this->makeSet($modul, 1, ['activated_at' => now()]);

        $soal = SoalTp::factory()->for($modul)->create(['soal' => 'Soal TP bobot sendiri']);
        $item = $this->makeItem($set, SoalVersionItem::SOAL_TP, $soal->id, ['bobot' => 40]);
        $this->makeItem($set, SoalVersionItem::SOAL_TP, 999001, ['bobot' => 60]);

        // Only the kunci changes; TP total stays 40 + 60 = 100.
        $this->putJson("/api-v1/soal-tp/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci bobot 50',
            'bobot' => 40,
        ])->assertOk();

        $this->assertSame(40, $item->fresh()->bobot);
    }

    public function test_bobot_totals_are_checked_per_soal_type(): void
    {
        $modul = $this->modul();
        $set = $this->makeSet($modul, 1, ['activated_at' => now()]);

        $this->makeItem($set, SoalVersionItem::SOAL_TP, 888001, ['bobot' => 100]);

        $soal = SoalMandiri::factory()->for($modul)->create(['soal' => 'Soal TM bobot bebas']);
        $this->makeItem($set, SoalVersionItem::SOAL_TM, $soal->id);

        // TM is a different type, so TP's 100 does not restrict it.
        $this->putJson("/api-v1/soal-tm/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci TM bobot 100',
            'bobot' => 100,
        ])->assertOk()->assertJsonPath('data.bobot', 100);
    }

    // ---------------------------------------------------------------------
    // Guards: validation & active version
    // ---------------------------------------------------------------------

    public function test_empty_kunci_returns_422(): void
    {
        $soal = SoalTp::factory()->for($this->modul())->create(['soal' => 'Soal TP kosong']);
        $item = $this->itemInActiveSet($soal, SoalVersionItem::SOAL_TP);

        $this->putJson("/api-v1/soal-tp/{$soal->id}/jawaban-standar", ['jawaban_standar' => ''])
            ->assertStatus(422);
        $this->putJson("/api-v1/soal-tp/{$soal->id}/jawaban-standar", [])
            ->assertStatus(422);

        $this->assertNull($item->fresh()->jawaban_standar);
    }

    public function test_without_active_version_set_returns_404_version_item_not_found(): void
    {
        $modul = $this->modul();
        $soal = SoalTp::factory()->for($modul)->create(['soal' => 'Soal TP tanpa versi aktif']);

        // A draft set exists, but it was never activated.
        $draft = $this->makeSet($modul, 1);
        $this->makeItem($draft, SoalVersionItem::SOAL_TP, $soal->id);

        $this->putJson("/api-v1/soal-tp/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci tanpa versi aktif',
        ])->assertStatus(404)
            ->assertJsonPath('code', 'VERSION_ITEM_NOT_FOUND');

        $this->assertNull($draft->items()->first()->jawaban_standar);
    }

    public function test_soal_missing_from_active_set_returns_404(): void
    {
        $modul = $this->modul();
        $set = $this->makeSet($modul, 1, ['activated_at' => now()]);
        $this->makeItem($set, SoalVersionItem::SOAL_TP, 777001);

        $soal = SoalTp::factory()->for($modul)->create(['soal' => 'Soal TP belum di versi']);

        $this->putJson("/api-v1/soal-tp/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci untuk soal yang tidak ada di set',
        ])->assertStatus(404)
            ->assertJsonPath('code', 'VERSION_ITEM_NOT_FOUND');
    }

    public function test_soal_of_another_modul_whose_active_set_lacks_item_returns_404(): void
    {
        $modul = $this->modul();
        $this->makeSet($modul, 1, ['activated_at' => now()]);

        // Question belongs to a modul without any version set at all.
        $soal = SoalTp::factory()->for($this->modul())->create(['soal' => 'Soal TP modul lain']);

        $this->putJson("/api-v1/soal-tp/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci modul lain',
        ])->assertStatus(404)->assertJsonPath('code', 'VERSION_ITEM_NOT_FOUND');
    }

    // ---------------------------------------------------------------------
    // Routing / authorization
    // ---------------------------------------------------------------------

    public function test_routes_require_asisten_auth_and_manage_soal(): void
    {
        $routes = app('router')->getRoutes();

        foreach ([
            'upsert.jawaban-standar.tp' => '/api-v1/soal-tp/{idSoal}/jawaban-standar',
            'upsert.jawaban-standar.tm' => '/api-v1/soal-tm/{idSoal}/jawaban-standar',
        ] as $name => $uri) {
            $route = $routes->getByName($name);
            $this->assertNotNull($route, "Route {$name} harus terdaftar.");
            $this->assertContains('PUT', $route->methods(), "{$name} harus berupa PUT.");
            $this->assertSame($uri, '/'.ltrim($route->uri(), '/'));
            $this->assertContains('auth:asisten', $route->gatherMiddleware(), "{$name} harus memakai auth:asisten.");
            $this->assertContains('permission:manage-soal', $route->gatherMiddleware(), "{$name} harus memakai permission:manage-soal.");
        }
    }

    public function test_guest_is_rejected(): void
    {
        $soal = SoalTp::factory()->for($this->modul())->create(['soal' => 'Soal TP guest']);
        $this->itemInActiveSet($soal, SoalVersionItem::SOAL_TP);

        auth('asisten')->logout();

        $this->putJson("/api-v1/soal-tp/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci guest',
        ])->assertUnauthorized();
    }

    public function test_asisten_without_manage_soal_gets_403(): void
    {
        $soal = SoalTp::factory()->for($this->modul())->create(['soal' => 'Soal TP tanpa permission']);
        $item = $this->itemInActiveSet($soal, SoalVersionItem::SOAL_TP);

        $this->actingAsAsistenNamed('jawaban-standar-tanpa-permission', 'see-soal');

        $this->putJson("/api-v1/soal-tp/{$soal->id}/jawaban-standar", [
            'jawaban_standar' => 'Kunci tanpa permission',
        ])->assertForbidden();

        $this->assertNull($item->fresh()->jawaban_standar);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Create an assistant holding exactly the given permission.
     */
    private function actingAsAsistenNamed(string $roleName, string $permissionName): Asisten
    {
        // Root role must exist: asistens.role_id is a foreign key to roles.id.
        Role::firstOrCreate([
            'name' => 'root',
            'guard_name' => 'asisten',
        ]);

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
     * @param  array<string, mixed>  $attributes
     */
    private function makeItem(SoalVersionSet $set, string $soalType, int $soalId, array $attributes = []): SoalVersionItem
    {
        return SoalVersionItem::create(array_merge([
            'version_set_id' => $set->id,
            'soal_type' => $soalType,
            'soal_id' => $soalId,
            'bobot' => 0,
            'max_score' => 100,
            'teks_snapshot' => 'Soal '.strtoupper($soalType).' '.$soalId,
            'jawaban_standar_status' => SoalVersionItem::STATUS_PENDING,
        ], $attributes))->fresh();
    }

    /**
     * Create an active set for the question's modul holding an item for it and
     * return that item.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function itemInActiveSet(SoalTp|SoalMandiri $soal, string $soalType, array $attributes = []): SoalVersionItem
    {
        $modul = Modul::query()->findOrFail($soal->modul_id);

        $set = SoalVersionSet::query()->forModul($modul->id)->active()->first()
            ?? $this->makeSet($modul, 1, ['activated_at' => now()]);

        return $this->makeItem($set, $soalType, $soal->id, $attributes);
    }
}
