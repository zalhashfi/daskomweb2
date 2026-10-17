<?php

namespace Tests\Feature;

use App\Models\Asisten;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLoggerActorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function logger(): AuditLogger
    {
        return app(AuditLogger::class);
    }

    private function createAssistant(): Asisten
    {
        $role = Role::create([
            'name' => 'TEST-AUDIT-ROLE',
            'guard_name' => 'asisten',
        ]);

        return Asisten::factory()->create([
            'role_id' => $role->id,
        ]);
    }

    public function test_log_from_request_attributes_row_to_logged_in_assistant(): void
    {
        $assistant = $this->createAssistant();
        $this->actingAs($assistant, 'asisten');

        $request = Request::create('/some/route', 'POST', [
            'nomor_telepon' => '08123456789',
        ]);
        $request->setUserResolver(fn () => $assistant);

        $this->logger()->logFromRequest($request, 'assistant.profile.update', 'Updated profile.');

        $log = AuditLog::latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('asisten', $log->actor_type);
        $this->assertSame($assistant->id, $log->actor_id);
        $this->assertSame($assistant->id, $log->asisten_id);
        $this->assertSame('assistant.profile.update', $log->action);
        $this->assertSame('POST', $log->method);
        $this->assertSame('Updated profile.', $log->description);
        $this->assertSame('08123456789', data_get($log->metadata, 'payload.nomor_telepon'));
    }

    public function test_log_from_request_redacts_sensitive_metadata_keys(): void
    {
        $assistant = $this->createAssistant();
        $this->actingAs($assistant, 'asisten');

        $request = Request::create('/some/route', 'PATCH', [
            'password' => 'password123',
            'token' => 'abc-token',
            'secret' => 'shh',
            'nested' => ['client_secret' => 'nested-secret', 'keep' => 'visible'],
        ]);

        $this->logger()->logFromRequest($request, 'assistant.password.update');

        $log = AuditLog::latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('[REDACTED]', data_get($log->metadata, 'payload.password'));
        $this->assertSame('[REDACTED]', data_get($log->metadata, 'payload.token'));
        $this->assertSame('[REDACTED]', data_get($log->metadata, 'payload.secret'));
        $this->assertSame('[REDACTED]', data_get($log->metadata, 'payload.nested.client_secret'));
        $this->assertSame('visible', data_get($log->metadata, 'payload.nested.keep'));
        $this->assertStringNotContainsString('password123', json_encode($log->metadata));
        $this->assertStringNotContainsString('abc-token', json_encode($log->metadata));
    }

    public function test_record_system_actor_has_null_actor_id(): void
    {
        $this->logger()->record(
            action: 'submission.auto_grade',
            description: 'Graded by scheduler.',
            actorType: 'system',
            actorId: 99,
        );

        $log = AuditLog::latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('system', $log->actor_type);
        $this->assertNull($log->actor_id);
        $this->assertNull($log->asisten_id);
        $this->assertSame('submission.auto_grade', $log->action);
        $this->assertSame('Graded by scheduler.', $log->description);
    }

    public function test_record_defaults_to_asisten_actor_type_with_unknown_actor(): void
    {
        $this->logger()->record('some.action');

        $log = AuditLog::latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('asisten', $log->actor_type);
        $this->assertNull($log->actor_id);
        $this->assertNull($log->subject_type);
        $this->assertNull($log->subject_id);
        $this->assertNull($log->metadata);
    }

    public function test_record_attributes_assistant_actor_id(): void
    {
        $assistant = $this->createAssistant();

        $this->logger()->record(
            action: 'submission.manual_grade',
            actorId: $assistant->id,
        );

        $log = AuditLog::latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('asisten', $log->actor_type);
        $this->assertSame($assistant->id, $log->actor_id);
        $this->assertSame($assistant->id, $log->asisten_id);
    }

    public function test_record_stores_subject_columns(): void
    {
        $this->logger()->record(
            action: 'submission.graded',
            subjectType: 'submission',
            subjectId: 42,
        );

        $log = AuditLog::where('subject_type', 'submission')
            ->where('subject_id', 42)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('submission.graded', $log->action);
    }

    public function test_record_sanitizes_sensitive_context_keys(): void
    {
        $this->logger()->record(
            action: 'user.updated',
            context: [
                'password' => 'supersecret',
                'token' => 'tok-123',
                'api_secret' => 'shh',
                'nested' => ['refresh_token' => 'refresh-123', 'name' => 'ok'],
                'name' => 'keep-me',
            ],
        );

        $log = AuditLog::latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('[REDACTED]', data_get($log->metadata, 'password'));
        $this->assertSame('[REDACTED]', data_get($log->metadata, 'token'));
        $this->assertSame('[REDACTED]', data_get($log->metadata, 'api_secret'));
        $this->assertSame('[REDACTED]', data_get($log->metadata, 'nested.refresh_token'));
        $this->assertSame('ok', data_get($log->metadata, 'nested.name'));
        $this->assertSame('keep-me', data_get($log->metadata, 'name'));

        $encoded = json_encode($log->metadata);
        $this->assertStringNotContainsString('supersecret', $encoded);
        $this->assertStringNotContainsString('tok-123', $encoded);
        $this->assertStringNotContainsString('refresh-123', $encoded);
    }
}
