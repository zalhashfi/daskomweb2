<?php

namespace Tests\Unit;

use App\Support\MigrationSafety;
use PHPUnit\Framework\TestCase;

class MigrationSafetyTest extends TestCase
{
    private MigrationSafety $safety;

    protected function setUp(): void
    {
        parent::setUp();

        $this->safety = new MigrationSafety;
    }

    public function test_migration_with_drop_column_is_irreversible(): void
    {
        $file = $this->writeMigration(<<<'PHP'
        public function up(): void
        {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('legacy_flag');
            });
        }
        PHP);

        $result = $this->safety->inspectFile($file);

        $this->assertSame(MigrationSafety::IRREVERSIBLE, $result['verdict']);
        $this->assertFalse($result['reversible']);
        $this->assertContains('dropColumn', $result['reasons']);
    }

    public function test_migration_with_only_drop_foreign_is_reversible(): void
    {
        $file = $this->writeMigration(<<<'PHP'
        public function up(): void
        {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['team_id']);
                $table->dropUnique(['email']);
                $table->dropIndex(['status']);
            });
        }
        PHP);

        $result = $this->safety->inspectFile($file);

        $this->assertSame(MigrationSafety::REVERSIBLE, $result['verdict']);
        $this->assertTrue($result['reversible']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_pure_schema_create_migration_is_reversible(): void
    {
        $file = $this->writeMigration(<<<'PHP'
        public function up(): void
        {
            Schema::create('soal_opsis', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('text');
                $table->index(['id'], 'soal_opsis_id_index');
            });
        }
        PHP);

        $result = $this->safety->inspectFile($file);

        $this->assertSame(MigrationSafety::REVERSIBLE, $result['verdict']);
        $this->assertTrue($result['reversible']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_known_destructive_migration_is_irreversible(): void
    {
        $path = dirname(__DIR__, 2).'/database/migrations/2025_10_27_003321_update_soal_question_schema.php';

        $this->assertFileExists($path);

        $result = $this->safety->inspectFile($path);

        $this->assertSame(MigrationSafety::IRREVERSIBLE, $result['verdict']);
        $this->assertFalse($result['reversible']);
        $this->assertNotSame([], $result['reasons']);
    }

    public function test_rename_column_and_raw_alter_reported(): void
    {
        $file = $this->writeMigration(<<<'PHP'
        public function up(): void
        {
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('name', 'full_name');
            });

            DB::statement('ALTER TABLE users DROP COLUMN nickname');
        }
        PHP);

        $result = $this->safety->inspectFile($file);

        $this->assertSame(MigrationSafety::IRREVERSIBLE, $result['verdict']);
        $this->assertContains('renameColumn', $result['reasons']);
        $this->assertContains('raw drop column', $result['reasons']);
    }

    private function writeMigration(string $body): string
    {
        $path = $this->migrationPath();
        file_put_contents($path, "<?php\n\nreturn new class {\n".$body."\n};\n");

        return $path;
    }

    private function migrationPath(): string
    {
        static $counter = 0;
        $counter++;

        return sys_get_temp_dir().'/migration_safety_'.getmypid().'_'.$counter.'.php';
    }
}
