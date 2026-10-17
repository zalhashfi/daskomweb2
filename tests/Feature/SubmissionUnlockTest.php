<?php

namespace Tests\Feature;

use App\Models\Asisten;
use App\Models\AuditLog;
use App\Models\JawabanMandiri;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalMandiri;
use App\Models\SoalTp;
use App\Models\SubmissionUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers POST /api-v1/jawaban-submissions/{id}/unlock.
 *
 * A kadiv (assistant holding `unlock-jawaban-submission`) may clear the lock of
 * a submission by providing a reason of at least 15 characters. The unlock is
 * stored in submission_unlocks and audited as 'submission.unlock'.
 */
class SubmissionUnlockTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'unlock-jawaban-submission';

    private const REASON = 'Jawaban terkirim karena kesalahan sistem.';

    /** Permission/middleware must run: no withoutMiddleware() on purpose. */
    protected function setUp(): void
    {
        parent::setUp();

        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actingAsAssistant(array $roleNames, array $permissions): Asisten
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'asisten']);
        }

        $primary = null;

        foreach ($roleNames as $roleName) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'asisten']);
            $role->syncPermissions($permissions);

            $primary ??= $role;
        }

        $assistant = Asisten::factory()->create(['role_id' => $primary->id]);
        $assistant->assignRole($roleNames);

        $this->actingAs($assistant, 'asisten');

        return $assistant;
    }

    private function actingAsKadiv(): Asisten
    {
        return $this->actingAsAssistant(['KORDAS'], [self::PERMISSION]);
    }

    private function makeLockedSubmission(): JawabanSubmission
    {
        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();

        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
            'attempt_no' => 1,
            'locked_at' => now(),
            'locked_by_type' => 'system',
            'locked_by_id' => null,
        ]);

        $soal = SoalTp::factory()->for($modul)->create();

        JawabanTp::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Jawaban praktikan.',
        ]);

        return $submission;
    }

    private function unlockUrl(int $submissionId): string
    {
        return "/api-v1/jawaban-submissions/{$submissionId}/unlock";
    }

    public function test_route_is_registered_with_expected_name_and_middleware(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route): bool => $route->getName() === 'unlock.jawaban-submission');

        $this->assertNotNull($route, 'Route unlock.jawaban-submission harus terdaftar.');
        $this->assertSame('api-v1/jawaban-submissions/{id}/unlock', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertContains('auth:asisten', $route->gatherMiddleware());
        $this->assertContains('permission:'.self::PERMISSION, $route->gatherMiddleware());
    }

    public function test_kadiv_can_unlock_locked_submission_with_valid_reason(): void
    {
        $kadiv = $this->actingAsKadiv();
        $submission = $this->makeLockedSubmission();

        $this->postJson($this->unlockUrl($submission->id), ['reason' => self::REASON])
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'submission_id' => $submission->id,
            ]);

        $fresh = $submission->fresh();
        $this->assertNull($fresh->locked_at);
        $this->assertNull($fresh->locked_by_type);
        $this->assertNull($fresh->locked_by_id);

        $this->assertDatabaseHas('submission_unlocks', [
            'submission_id' => $submission->id,
            'unlocked_by_asisten_id' => $kadiv->id,
            'reason' => self::REASON,
        ]);

        $unlock = SubmissionUnlock::query()->where('submission_id', $submission->id)->firstOrFail();
        $this->assertSame($kadiv->id, $unlock->unlocked_by_asisten_id);
        $this->assertNotNull($unlock->created_at);
    }

    public function test_unlock_writes_audit_log_with_kadiv_actor_and_reason(): void
    {
        $kadiv = $this->actingAsKadiv();
        $submission = $this->makeLockedSubmission();

        $this->postJson($this->unlockUrl($submission->id), ['reason' => self::REASON])
            ->assertOk();

        $log = AuditLog::query()
            ->where('action', 'submission.unlock')
            ->where('subject_type', 'submission')
            ->where('subject_id', $submission->id)
            ->first();

        $this->assertNotNull($log, 'Audit submission.unlock harus tertulis.');
        $this->assertSame('kadiv', $log->actor_type);
        $this->assertSame($kadiv->id, $log->actor_id);
        $this->assertSame(self::REASON, $log->metadata['reason'] ?? null);
    }

    public function test_kadiv_can_unlock_tm_submission(): void
    {
        $this->actingAsKadiv();

        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();

        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TM,
            'attempt_no' => 1,
            'locked_at' => now(),
            'locked_by_type' => 'asisten',
            'locked_by_id' => 1,
        ]);

        $soal = SoalMandiri::factory()->for($modul)->create();
        JawabanMandiri::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Jawaban mandiri.',
        ]);

        $this->postJson($this->unlockUrl($submission->id), ['reason' => self::REASON])
            ->assertOk()
            ->assertJsonPath('submission_id', $submission->id);

        $fresh = $submission->fresh();
        $this->assertNull($fresh->locked_at);
        $this->assertNull($fresh->locked_by_type);
        $this->assertNull($fresh->locked_by_id);
    }

    public function test_reason_shorter_than_minimum_returns_422_and_keeps_lock(): void
    {
        $this->actingAsKadiv();
        $submission = $this->makeLockedSubmission();
        $lockedAt = $submission->locked_at;

        $this->postJson($this->unlockUrl($submission->id), ['reason' => 'salah kirim'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $fresh = $submission->fresh();
        $this->assertNotNull($fresh->locked_at, 'locked_at tidak boleh berubah saat validasi gagal.');
        $this->assertEquals($lockedAt, $fresh->locked_at);
        $this->assertSame('system', $fresh->locked_by_type);

        $this->assertDatabaseMissing('submission_unlocks', ['submission_id' => $submission->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'submission.unlock',
            'subject_type' => 'submission',
            'subject_id' => $submission->id,
        ]);
    }

    public function test_missing_reason_returns_422_and_keeps_lock(): void
    {
        $this->actingAsKadiv();
        $submission = $this->makeLockedSubmission();

        $this->postJson($this->unlockUrl($submission->id), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertNotNull($submission->fresh()->locked_at);
        $this->assertDatabaseMissing('submission_unlocks', ['submission_id' => $submission->id]);
    }

    public function test_reason_with_exactly_fifteen_characters_is_accepted(): void
    {
        $this->actingAsKadiv();
        $submission = $this->makeLockedSubmission();
        $reason = str_repeat('a', 15);

        $this->postJson($this->unlockUrl($submission->id), ['reason' => $reason])
            ->assertOk();

        $this->assertNull($submission->fresh()->locked_at);
        $this->assertDatabaseHas('submission_unlocks', [
            'submission_id' => $submission->id,
            'reason' => $reason,
        ]);
    }

    public function test_asisten_without_permission_gets_403_and_lock_is_untouched(): void
    {
        $this->actingAsAssistant(['ASISTEN'], ['nilai-praktikan']);
        $submission = $this->makeLockedSubmission();

        $this->postJson($this->unlockUrl($submission->id), ['reason' => self::REASON])
            ->assertForbidden();

        $this->assertNotNull($submission->fresh()->locked_at);
        $this->assertDatabaseMissing('submission_unlocks', ['submission_id' => $submission->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'submission.unlock']);
    }

    public function test_missing_submission_returns_404(): void
    {
        $this->actingAsKadiv();

        $this->postJson($this->unlockUrl(999999), ['reason' => self::REASON])
            ->assertNotFound();
    }

    public function test_praktikan_cannot_unlock(): void
    {
        $submission = $this->makeLockedSubmission();
        $this->actingAs($submission->praktikan, 'praktikan');

        $this->postJson($this->unlockUrl($submission->id), ['reason' => self::REASON])
            ->assertStatus(401);

        $this->assertNotNull($submission->fresh()->locked_at);
    }

    public function test_migration_is_reversible(): void
    {
        $this->assertTrue(Schema::hasTable('submission_unlocks'));
        foreach (['id', 'submission_id', 'unlocked_by_asisten_id', 'reason', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('submission_unlocks', $column), "submission_unlocks.{$column} harus ada.");
        }

        $path = collect(glob(database_path('migrations/*.php')))
            ->first(fn (string $file): bool => str_contains(basename($file), 'create_submission_unlocks_table'));
        $this->assertNotNull($path, 'Migration create_submission_unlocks_table tidak ditemukan.');

        $migration = require $path;

        $migration->down();
        $this->assertFalse(Schema::hasTable('submission_unlocks'), 'down() harus menghapus tabel.');

        $migration->up();
        $this->assertTrue(Schema::hasTable('submission_unlocks'), 'up() harus membuat kembali tabel.');
        $this->assertTrue(Schema::hasColumn('submission_unlocks', 'reason'));
    }
}
