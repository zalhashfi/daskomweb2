<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PreflightEnv extends Command
{
    /**
     * Environment variables that must be present and non-empty for production.
     *
     * @var list<string>
     */
    public const REQUIRED_VARS = [
        'APP_KEY',
        'APP_ENV',
        'APP_URL',
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'REDIS_HOST',
        'IMAGEKIT_PUBLIC_KEY',
        'IMAGEKIT_PRIVATE_KEY',
        'IMAGEKIT_ENDPOINT_URL',
        'JWT_SECRET',
        'REVERB_APP_KEY',
        'REVERB_APP_SECRET',
        'REVERB_APP_ID',
        'SESSION_DOMAIN',
        'SANCTUM_STATEFUL_DOMAINS',
    ];

    /**
     * Values that indicate a development environment rather than production.
     *
     * @var array<string, string>
     */
    public const DEVELOPMENT_VALUES = [
        'APP_DEBUG' => 'true',
        'APP_ENV' => 'local',
        'SESSION_DOMAIN' => 'localhost',
        'SANCTUM_STATEFUL_DOMAINS' => 'localhost',
    ];

    protected $signature = 'deploy:preflight-env';

    protected $description = 'Verify the environment is production-ready before deploying (read-only).';

    /**
     * Resolve the current value of an environment variable.
     *
     * Laravel's env() helper coerces reserved words like "true"/"false"/"null"
     * into their native PHP types. Normalize those back to the raw string so the
     * development-value checks can compare the literal configuration value.
     */
    protected function environmentValue(string $key): ?string
    {
        $value = env($key);

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Read the development APP_KEY advertised in .env.example, if available.
     */
    protected function exampleAppKey(): ?string
    {
        $path = base_path('.env.example');

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if (! str_starts_with($line, 'APP_KEY=') && ! str_starts_with($line, 'export APP_KEY=')) {
                continue;
            }

            $value = trim(substr($line, strpos($line, 'APP_KEY=') + strlen('APP_KEY=')));

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];

                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            return $value;
        }

        return null;
    }

    /**
     * Evaluate the environment and build the machine-readable report.
     *
     * @return array{missing: list<string>, development: array<string, string>, problems: list<string>, ok: bool}
     */
    public function buildReport(): array
    {
        $missing = [];
        $development = [];

        foreach (self::REQUIRED_VARS as $key) {
            $value = $this->environmentValue($key);

            if ($value === null || trim($value) === '') {
                $missing[] = $key;
            }
        }

        foreach (self::DEVELOPMENT_VALUES as $key => $devValue) {
            $value = $this->environmentValue($key);

            if ($value !== null && trim($value) === $devValue) {
                $development[$key] = $devValue;
            }
        }

        $exampleKey = $this->exampleAppKey();
        $appKey = $this->environmentValue('APP_KEY');

        if ($exampleKey !== null && $exampleKey !== '' && $appKey !== null && $appKey === $exampleKey) {
            $development['APP_KEY'] = 'example';
        }

        $problems = [];

        foreach ($missing as $key) {
            $problems[] = "missing: {$key}";
        }

        foreach ($development as $key => $devValue) {
            $problems[] = 'development value: '.($devValue === 'example' ? "{$key} matches .env.example" : "{$key}={$devValue}");
        }

        return [
            'missing' => $missing,
            'development' => $development,
            'problems' => $problems,
            'ok' => $problems === [],
        ];
    }

    public function handle(): int
    {
        $report = $this->buildReport();

        $payload = [
            'ok' => $report['ok'],
            'missing' => array_values($report['missing']),
            'development' => (object) $report['development'],
            'problems' => $report['problems'],
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->output->writeln($json === false ? '{}' : $json);

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
