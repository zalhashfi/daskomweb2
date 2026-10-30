<?php

namespace Tests\Feature;

use App\Enums\TipeSoal;
use App\Models\Asisten;
use App\Models\Kelas;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalComment;
use App\Models\SoalTa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SoalCommentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutCsrf();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_tot_praktikan_can_store_comment(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'praktikum-lms',
            'guard_name' => 'praktikan',
        ]);

        $role = Role::firstOrCreate([
            'name' => 'tot-praktikan',
            'guard_name' => 'praktikan',
        ]);

        $role->givePermissionTo($permission);

        $kelas = Kelas::factory()->create(['kelas' => 'TOT-01']);
        $praktikan = Praktikan::factory()->for($kelas)->create();
        $praktikan->assignRole($role);

        $modul = Modul::factory()->create();
        $soal = SoalTa::factory()->create(['modul_id' => $modul->id]);

        $this->actingAs($praktikan, 'praktikan');

        $response = $this->postJson(
            sprintf('/api-v1/praktikan/soal-comment/%d/ta/%d', $praktikan->id, $soal->id),
            ['comment' => 'Bagian ini membuat bingung.']
        );

        $response->assertCreated();
        $response->assertJsonPath('data.praktikan.id', $praktikan->id);
        $response->assertJsonPath('data.tipe_soal', TipeSoal::TA->value);

        $this->assertDatabaseHas('soal_comments', [
            'soal_id' => $soal->id,
            'praktikan_id' => $praktikan->id,
            'tipe_soal' => TipeSoal::TA->value,
            'comment' => 'Bagian ini membuat bingung.',
        ]);
    }

    public function test_non_tot_praktikan_cannot_store_comment(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'praktikum-lms',
            'guard_name' => 'praktikan',
        ]);

        $role = Role::firstOrCreate([
            'name' => 'regular-praktikan',
            'guard_name' => 'praktikan',
        ]);

        $role->givePermissionTo($permission);

        $kelas = Kelas::factory()->create(['kelas' => 'REG-01']);
        $praktikan = Praktikan::factory()->for($kelas)->create();
        $praktikan->assignRole($role);

        $modul = Modul::factory()->create();
        $soal = SoalTa::factory()->create(['modul_id' => $modul->id]);

        $this->actingAs($praktikan, 'praktikan');

        $response = $this->postJson(
            sprintf('/api-v1/praktikan/soal-comment/%d/ta/%d', $praktikan->id, $soal->id),
            ['comment' => 'Saya ingin bertanya.']
        );

        $response->assertForbidden();

        $this->assertDatabaseMissing('soal_comments', [
            'praktikan_id' => $praktikan->id,
            'soal_id' => $soal->id,
        ]);
    }

    public function test_assistant_only_sees_tot_comments(): void
    {
        $assistantPermission = Permission::firstOrCreate([
            'name' => 'nilai-praktikan',
            'guard_name' => 'asisten',
        ]);

        $assistantRole = Role::firstOrCreate([
            'name' => 'assistant-reviewer',
            'guard_name' => 'asisten',
        ]);

        $assistantRole->givePermissionTo($assistantPermission);

        $assistant = Asisten::factory()->create([
            'role_id' => $assistantRole->id,
        ]);
        $assistant->assignRole($assistantRole);

        $totPermission = Permission::firstOrCreate([
            'name' => 'praktikum-lms',
            'guard_name' => 'praktikan',
        ]);

        $totRole = Role::firstOrCreate([
            'name' => 'tot-praktikan',
            'guard_name' => 'praktikan',
        ]);
        $totRole->givePermissionTo($totPermission);

        $totKelas = Kelas::factory()->create(['kelas' => 'TOT-02']);
        $regularKelas = Kelas::factory()->create(['kelas' => 'REG-02']);

        $totPraktikan = Praktikan::factory()->for($totKelas)->create();
        $totPraktikan->assignRole($totRole);

        $regularPraktikan = Praktikan::factory()->for($regularKelas)->create();
        $regularPraktikan->assignRole($totRole);

        $modul = Modul::factory()->create(['isUnlocked' => true]);
        $soal = SoalTa::factory()->create(['modul_id' => $modul->id]);

        SoalComment::query()->create([
            'soal_id' => $soal->id,
            'tipe_soal' => TipeSoal::TA,
            'praktikan_id' => $totPraktikan->id,
            'comment' => 'TOT question comment',
        ]);

        SoalComment::query()->create([
            'soal_id' => $soal->id,
            'tipe_soal' => TipeSoal::TA,
            'praktikan_id' => $regularPraktikan->id,
            'comment' => 'Regular class comment',
        ]);

        $this->actingAs($assistant, 'asisten');

        $response = $this->getJson(sprintf('/api-v1/asisten/soal-comment/ta/%d', $modul->id));

        $response->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.praktikan.id', $totPraktikan->id);
        $response->assertJsonPath('data.0.comment', 'TOT question comment');
    }
}
