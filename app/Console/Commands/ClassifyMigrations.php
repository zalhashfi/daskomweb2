<?php

namespace App\Console\Commands;

use App\Support\MigrationSafety;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Collection;

/**
 * Read-only deployment gate: report whether the migrations that are still
 * PENDING are safe to apply.
 *
 * The command never migrates or rolls back anything. It resolves the pending
 * migration set through the injected migrator + migration repository, feeds
 * each pending file to the T05 {@see MigrationSafety} classifier, and prints a
 * JSON verdict to stdout.
 *
 * Exit code 0 => every pending migration is reversible.
 * Exit code 1 => at least one pending migration is irreversible.
 */
class ClassifyMigrations extends Command
{
    /**
     * @var string
     */
    protected $signature = 'deploy:classify-migrations';

    /**
     * @var string
     */
    protected $description = 'Classify pending migrations as reversible or irreversible (read-only; JSON to stdout)';

    protected Migrator $migrator;

    protected MigrationSafety $safety;

    public function __construct(Migrator $migrator, MigrationSafety $safety)
    {
        parent::__construct();

        $this->migrator = $migrator;
        $this->safety = $safety;
    }

    public function handle(): int
    {
        $irreversible = [];

        foreach ($this->pendingMigrations() as $path) {
            $result = $this->safety->inspectFile($path);

            if (! $result['reversible']) {
                $irreversible[] = [
                    'file' => basename($path),
                    'reasons' => array_values($result['reasons']),
                ];
            }
        }

        $reversible = $irreversible === [];

        $payload = [
            'reversible' => $reversible,
            'irreversible' => $irreversible,
        ];

        $this->output->writeln((string) json_encode($payload));

        return $reversible ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Resolve the migration files that have not been run yet.
     *
     * @return array<int, string> Absolute paths to pending migration files.
     */
    protected function pendingMigrations(): array
    {
        $files = $this->migrator->getMigrationFiles($this->migrationPaths());
        $ran = $this->migrator->getRepository()->getRan();

        return (new Collection($files))
            ->reject(fn (string $path, string $name): bool => in_array($name, $ran, true))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function migrationPaths(): array
    {
        return array_merge(
            $this->migrator->paths(),
            [$this->laravel->databasePath().DIRECTORY_SEPARATOR.'migrations']
        );
    }
}
