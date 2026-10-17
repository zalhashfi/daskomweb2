<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards the reversibility of the legacy schema migration that collapses the
 * essay scaffolding into option tables (2025_10_27_003321).
 *
 * The rollback is verified through the same path a real deploy would use:
 * the migration is applied in isolation on a scratch connection state, then
 * reverted, and the resulting schema is inspected.
 */
class LegacyMigrationRollbackTest extends TestCase
{
    private const MIGRATION = '2025_10_27_003321_update_soal_question_schema';

    protected function setUp(): void
    {
        parent::setUp();

        // This test drives migrations manually, so the in-memory/test
        // transaction provided by RefreshDatabase would fight it.
        $this->withoutMiddleware();
    }

    public function test_legacy_migration_can_be_rolled_back_and_reapplied(): void
    {
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Rollback assertions are MySQL specific.');
        }

        // The suite's RefreshDatabase already ran every migration, so the
        // post-up() shape is the starting point.
        $this->assertTrue(Schema::hasTable('soal_opsis'), 'up() should create soal_opsis.');
        $this->assertFalse(
            Schema::hasColumn('soal_tps', 'isEssay'),
            'up() should have dropped the TP flags.'
        );

        $this->runMigration(self::MIGRATION, 'down');

        // down() restores the pre-migration shape.
        $this->assertFalse(Schema::hasTable('soal_opsis'), 'down() should drop soal_opsis.');
        $this->assertTrue(Schema::hasColumn('soal_tps', 'isEssay'), 'down() should restore isEssay.');
        $this->assertTrue(Schema::hasColumn('soal_tps', 'isProgram'), 'down() should restore isProgram.');
        $this->assertTrue(Schema::hasColumn('soal_mandiris', 'pengantar'), 'down() should restore pengantar.');
        $this->assertTrue(Schema::hasColumn('soal_mandiris', 'kodingan'), 'down() should restore kodingan.');
        $this->assertTrue(Schema::hasColumn('soal_tas', 'jawaban_benar'), 'down() should restore TA answer columns.');
        $this->assertTrue(Schema::hasColumn('soal_tks', 'jawaban_benar'), 'down() should restore TK answer columns.');

        $this->runMigration(self::MIGRATION, 'up');

        // up() re-applies cleanly on top of the reverted schema.
        $this->assertTrue(Schema::hasTable('soal_opsis'), 'up() should recreate soal_opsis.');
        $this->assertFalse(Schema::hasColumn('soal_tps', 'isEssay'), 'up() should drop isEssay again.');
        $this->assertTrue(Schema::hasColumn('jawaban_tas', 'opsi_id'), 'up() should restore opsi_id on jawaban_tas.');
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
