<?php

namespace Tests\Feature;

use App\Console\Commands\ClassifyMigrations;
use App\Support\MigrationSafety;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deterministic coverage for the `deploy:classify-migrations` command.
 *
 * The pending set is driven entirely by fixtures bound into the container
 * (a fake migrator + fake repository). Nothing here depends on the migration
 * state of the repository itself, and no migration is ever executed.
 */
class ClassifyMigrationsCommandTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $fixtureFiles = [];

    private string $fixtureDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureDirectory = sys_get_temp_dir().'/classify_migrations_'.getmypid().'_'.uniqid();
        mkdir($this->fixtureDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        if ($this->fixtureDirectory !== '' && is_dir($this->fixtureDirectory)) {
            @rmdir($this->fixtureDirectory);
        }

        parent::tearDown();
    }

    public function test_pending_set_with_irreversible_migration_exits_one_and_names_the_file(): void
    {
        $known = $this->copyKnownIrreversibleMigration();
        $reversible = $this->writeMigration('2026_01_01_000000_create_widgets_table.php', <<<'PHP'
        public function up(): void
        {
            Schema::create('widgets', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
            });
        }
        PHP);

        $this->bindPendingMigrations([$known, $reversible]);

        $exitCode = $this->runCommand();

        $this->assertSame(1, $exitCode);

        $payload = $this->decodeOutput();

        $this->assertSame(['reversible', 'irreversible'], array_keys($payload));
        $this->assertFalse($payload['reversible']);
        $this->assertNotSame([], $payload['irreversible']);
        $this->assertSame(
            '2025_10_27_003321_update_soal_question_schema.php',
            $payload['irreversible'][0]['file']
        );
        $this->assertIsArray($payload['irreversible'][0]['reasons']);
        $this->assertNotSame([], $payload['irreversible'][0]['reasons']);
    }

    public function test_pending_set_of_only_reversible_migrations_exits_zero(): void
    {
        $first = $this->writeMigration('2026_01_01_000000_create_widgets_table.php', <<<'PHP'
        public function up(): void
        {
            Schema::create('widgets', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
            });
        }
        PHP);

        $second = $this->writeMigration('2026_01_02_000000_add_index_to_widgets_table.php', <<<'PHP'
        public function up(): void
        {
            Schema::table('widgets', function (Blueprint $table) {
                $table->index(['name'], 'widgets_name_index');
            });
        }
        PHP);

        $this->bindPendingMigrations([$first, $second]);

        $exitCode = $this->runCommand();

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeOutput();

        $this->assertSame(['reversible', 'irreversible'], array_keys($payload));
        $this->assertTrue($payload['reversible']);
        $this->assertSame([], $payload['irreversible']);
    }

    public function test_ran_migrations_are_not_inspected(): void
    {
        // This file is destructive, but it is already marked as ran, so it must
        // never appear in the pending verdict.
        $ranDestructive = $this->writeMigration('2026_01_03_000000_drop_from_widgets_table.php', <<<'PHP'
        public function up(): void
        {
            Schema::table('widgets', function (Blueprint $table) {
                $table->dropColumn('legacy');
            });
        }
        PHP);

        $pendingReversible = $this->writeMigration('2026_01_04_000000_create_gadgets_table.php', <<<'PHP'
        public function up(): void
        {
            Schema::create('gadgets', function (Blueprint $table) {
                $table->bigIncrements('id');
            });
        }
        PHP);

        $this->bindPendingMigrations(
            [$ranDestructive, $pendingReversible],
            ran: ['2026_01_03_000000_drop_from_widgets_table']
        );

        $exitCode = $this->runCommand();

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeOutput();

        $this->assertTrue($payload['reversible']);
        $this->assertSame([], $payload['irreversible']);
    }

    public function test_command_has_no_side_effects_on_migrations_table(): void
    {
        $known = $this->copyKnownIrreversibleMigration();

        $this->bindPendingMigrations([$known]);

        $before = $this->migrationTableState();

        $exitCode = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertSame($before, $this->migrationTableState());
    }

    public function test_command_is_non_interactive_friendly(): void
    {
        $this->bindPendingMigrations([
            $this->writeMigration('2026_01_01_000000_create_widgets_table.php', <<<'PHP'
            public function up(): void
            {
                Schema::create('widgets', function (Blueprint $table) {
                    $table->bigIncrements('id');
                });
            }
            PHP),
        ]);

        $exitCode = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertSame([], $this->decodeOutput()['irreversible']);
    }

    /**
     * Bind a fake migrator + repository so the pending set comes from fixtures.
     *
     * @param  array<int, string>  $pendingFiles
     * @param  array<int, string>  $ran
     */
    private function bindPendingMigrations(array $pendingFiles, array $ran = []): void
    {
        $repository = \Mockery::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('getRan')->andReturn($ran);

        $migrator = \Mockery::mock(Migrator::class);
        $migrator->shouldReceive('getRepository')->andReturn($repository);
        $migrator->shouldReceive('paths')->andReturn([]);

        $files = [];
        foreach ($pendingFiles as $path) {
            $files[str_replace('.php', '', basename($path))] = $path;
        }

        $migrator->shouldReceive('getMigrationFiles')->andReturn($files);

        // The command must never run or roll back anything.
        $migrator->shouldNotReceive('run');
        $migrator->shouldNotReceive('runPending');
        $migrator->shouldNotReceive('rollback');
        $migrator->shouldNotReceive('reset');

        $this->app->instance(Migrator::class, $migrator);
        $this->app->instance('migrator', $migrator);
        $this->app->instance(MigrationSafety::class, new MigrationSafety);
    }

    private function runCommand(): int
    {
        return Artisan::call(ClassifyMigrations::class);
    }

    /**
     * @return array{reversible: bool, irreversible: array<int, array{file: string, reasons: array<int, string>}>}
     */
    private function decodeOutput(): array
    {
        $output = trim(Artisan::output());

        $this->assertNotSame('', $output, 'Command produced no stdout output.');

        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, 'Command output was not valid JSON.');
        $this->assertArrayHasKey('reversible', $decoded);
        $this->assertArrayHasKey('irreversible', $decoded);

        return $decoded;
    }

    /**
     * @return array{migrations: array<int, string>, count: int}
     */
    private function migrationTableState(): array
    {
        return [
            'migrations' => DB::table('migrations')->orderBy('migration')->pluck('migration')->all(),
            'count' => DB::table('migrations')->count(),
        ];
    }

    private function copyKnownIrreversibleMigration(): string
    {
        $source = dirname(__DIR__, 2).'/database/migrations/2025_10_27_003321_update_soal_question_schema.php';

        $this->assertFileExists($source);

        return $this->writeMigration(
            '2025_10_27_003321_update_soal_question_schema.php',
            null,
            (string) file_get_contents($source)
        );
    }

    private function writeMigration(string $basename, ?string $body = null, ?string $raw = null): string
    {
        $path = $this->fixtureDirectory.'/'.$basename;

        $contents = $raw ?? "<?php\n\nreturn new class {\n".$body."\n};\n";

        file_put_contents($path, $contents);
        $this->fixtureFiles[] = $path;

        return $path;
    }
}
