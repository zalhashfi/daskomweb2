<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditLogSubjectTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_17_090000_add_subject_and_actor_to_audit_logs';

    private function baseAttributes(array $overrides = []): array
    {
        return array_merge([
            'action' => 'test.action',
            'route' => 'test.route',
            'method' => 'POST',
            'description' => 'Test audit entry.',
        ], $overrides);
    }

    public function test_subject_and_actor_columns_exist_after_migration(): void
    {
        foreach (['subject_type', 'subject_id', 'actor_type', 'actor_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('audit_logs', $column), "audit_logs.{$column} should exist.");
        }

        $this->assertTrue(
            $this->hasSubjectIndex(),
            'audit_logs_subject_index should exist on audit_logs (subject_type, subject_id, created_at).'
        );
    }

    public function test_subject_and_actor_attributes_are_persisted_and_readable(): void
    {
        $log = AuditLog::create($this->baseAttributes([
            'subject_type' => 'soal_version_set',
            'subject_id' => 1,
            'actor_type' => 'admin',
            'actor_id' => 42,
        ]));

        $fresh = AuditLog::findOrFail($log->id);

        $this->assertSame('soal_version_set', $fresh->subject_type);
        $this->assertSame(1, $fresh->subject_id);
        $this->assertSame('admin', $fresh->actor_type);
        $this->assertSame(42, $fresh->actor_id);
    }

    public function test_actor_type_defaults_to_asisten_when_omitted(): void
    {
        $log = AuditLog::create($this->baseAttributes([
            'subject_type' => 'soal_version_set',
            'subject_id' => 2,
        ]));

        $fresh = AuditLog::findOrFail($log->id);

        $this->assertSame('asisten', $fresh->actor_type);
        $this->assertNull($fresh->actor_id);
    }

    public function test_legacy_rows_without_actor_type_receive_default(): void
    {
        // Simulates a row written before the migration by bypassing the model.
        $id = DB::table('audit_logs')->insertGetId([
            'action' => 'legacy.action',
            'route' => 'legacy.route',
            'method' => 'GET',
            'description' => 'Legacy entry.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('asisten', DB::table('audit_logs')->where('id', $id)->value('actor_type'));
    }

    public function test_query_by_subject_returns_exactly_one_record(): void
    {
        AuditLog::create($this->baseAttributes([
            'subject_type' => 'soal_version_set',
            'subject_id' => 1,
            'description' => 'First.',
        ]));

        AuditLog::create($this->baseAttributes([
            'subject_type' => 'soal_version_set',
            'subject_id' => 2,
            'description' => 'Other subject.',
        ]));

        AuditLog::create($this->baseAttributes([
            'subject_type' => 'question',
            'subject_id' => 1,
            'description' => 'Other type.',
        ]));

        $results = AuditLog::where('subject_type', 'soal_version_set')
            ->where('subject_id', 1)
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('First.', $results->first()->description);
    }

    public function test_migration_is_reversible(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Rollback assertions are MySQL specific.');
        }

        // RefreshDatabase already applied every migration.
        $this->assertTrue(Schema::hasColumn('audit_logs', 'subject_type'));
        $this->assertTrue($this->hasSubjectIndex());

        $this->runMigration(self::MIGRATION, 'down');

        foreach (['subject_type', 'subject_id', 'actor_type', 'actor_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('audit_logs', $column), "down() should drop {$column}.");
        }
        $this->assertFalse($this->hasSubjectIndex(), 'down() should drop audit_logs_subject_index.');

        $this->runMigration(self::MIGRATION, 'up');

        foreach (['subject_type', 'subject_id', 'actor_type', 'actor_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('audit_logs', $column), "up() should restore {$column}.");
        }
        $this->assertTrue($this->hasSubjectIndex(), 'up() should recreate audit_logs_subject_index.');

        // The restored column still carries the 'asisten' default.
        $row = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'audit_logs')
            ->where('column_name', 'actor_type')
            ->select('column_default as column_default')
            ->first();

        $this->assertSame('asisten', $row->column_default);
    }

    private function hasSubjectIndex(): bool
    {
        if (method_exists(Schema::class, 'hasIndex')) {
            try {
                return Schema::hasIndex('audit_logs', 'audit_logs_subject_index');
            } catch (\Throwable) {
                // Fall through to information_schema.
            }
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'audit_logs')
            ->where('index_name', 'audit_logs_subject_index')
            ->exists();
    }

    private function runMigration(string $name, string $direction): void
    {
        $path = collect(glob(database_path('migrations/*.php')))
            ->first(fn (string $file): bool => str_contains(basename($file), $name));

        $this->assertNotNull($path, "Migration {$name} not found.");

        $migration = require $path;
        $migration->{$direction}();
    }
}
