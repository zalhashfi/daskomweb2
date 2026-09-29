<?php

namespace Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class PreflightEnvCommandTest extends TestCase
{
    /**
     * A fully production-ready set of environment values.
     *
     * @var array<string, string>
     */
    private const PRODUCTION_ENV = [
        'APP_KEY' => 'base64:PR0DUCTIONKEYfJ8sQ2vLmN4pXzR7tYwKbD9hGcEaZ6uVo=',
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_URL' => 'https://lms.example.com',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '10.0.0.5',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'lms_production',
        'DB_USERNAME' => 'lms_user',
        'DB_PASSWORD' => 'super-secret-password',
        'REDIS_HOST' => '10.0.0.9',
        'IMAGEKIT_PUBLIC_KEY' => 'public_live_key',
        'IMAGEKIT_PRIVATE_KEY' => 'private_live_key',
        'IMAGEKIT_ENDPOINT_URL' => 'https://ik.imagekit.io/lms',
        'JWT_SECRET' => 'jwt-live-secret',
        'REVERB_APP_KEY' => 'reverb-live-key',
        'REVERB_APP_SECRET' => 'reverb-live-secret',
        'REVERB_APP_ID' => '123456',
        'SESSION_DOMAIN' => 'lms.example.com',
        'SANCTUM_STATEFUL_DOMAINS' => 'lms.example.com',
    ];

    /**
     * Original .env content restored after each test.
     */
    private ?string $originalEnvContents = null;

    private bool $envFileExisted = false;

    /**
     * Environment keys touched by the tests, restored afterwards.
     *
     * @var list<string>
     */
    private array $managedKeys = [];

    /**
     * Original getenv() values for managed keys, captured before mutation so
     * tearDown can RESTORE them instead of unsetting shell-provided variables
     * (unsetting DB_* leaked into later tests: 'Access denied ... password: NO').
     *
     * @var array<string, string|false>
     */
    private array $originalEnvValues = [];

    /**
     * Mask the real .env so the test-controlled process environment is authoritative.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $path = base_path('.env');

        $this->envFileExisted = is_file($path);

        if ($this->envFileExisted) {
            $this->originalEnvContents = (string) file_get_contents($path);
            unlink($path);
        }

        $this->reloadEnvironment();
    }

    protected function tearDown(): void
    {
        $this->forgetManagedEnv();

        $path = base_path('.env');

        if ($this->envFileExisted) {
            file_put_contents($path, (string) $this->originalEnvContents);
        } elseif (is_file($path)) {
            unlink($path);
        }

        $this->reloadEnvironment();

        parent::tearDown();
    }

    public function test_missing_required_vars_fail_and_are_named_in_json(): void
    {
        $this->applyProductionEnv();

        $missing = ['IMAGEKIT_PUBLIC_KEY', 'JWT_SECRET', 'REVERB_APP_ID'];

        foreach ($missing as $key) {
            $this->forgetEnv($key);
        }

        [$exitCode, $report] = $this->runPreflight();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['ok']);
        $this->assertSame($missing, $report['missing']);
        $this->assertSame([], (array) $report['development']);
    }

    public function test_local_environment_fails(): void
    {
        $this->applyProductionEnv();
        $this->setEnv('APP_ENV', 'local');

        [$exitCode, $report] = $this->runPreflight();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['ok']);
        $this->assertSame([], $report['missing']);
        $this->assertSame('local', ((array) $report['development'])['APP_ENV']);
    }

    public function test_localhost_session_domain_fails(): void
    {
        $this->applyProductionEnv();
        $this->setEnv('SESSION_DOMAIN', 'localhost');

        [$exitCode, $report] = $this->runPreflight();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['ok']);
        $this->assertSame('localhost', ((array) $report['development'])['SESSION_DOMAIN']);
    }

    public function test_localhost_sanctum_stateful_domains_fails(): void
    {
        $this->applyProductionEnv();
        $this->setEnv('SANCTUM_STATEFUL_DOMAINS', 'localhost');

        [$exitCode, $report] = $this->runPreflight();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['ok']);
        $this->assertSame('localhost', ((array) $report['development'])['SANCTUM_STATEFUL_DOMAINS']);
    }

    public function test_development_app_debug_fails(): void
    {
        $this->applyProductionEnv();
        $this->setEnv('APP_DEBUG', 'true');

        [$exitCode, $report] = $this->runPreflight();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['ok']);
        $this->assertSame('true', ((array) $report['development'])['APP_DEBUG']);
    }

    public function test_app_key_equal_to_example_fails(): void
    {
        $this->applyProductionEnv();

        $exampleKey = $this->exampleAppKey();

        if ($exampleKey === null) {
            $this->markTestSkipped('.env.example is not available to compare the development APP_KEY.');
        }

        $this->setEnv('APP_KEY', $exampleKey);

        [$exitCode, $report] = $this->runPreflight();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['ok']);
        $this->assertSame('example', ((array) $report['development'])['APP_KEY']);
    }

    public function test_fully_production_environment_passes(): void
    {
        $this->applyProductionEnv();

        [$exitCode, $report] = $this->runPreflight();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['missing']);
        $this->assertSame([], (array) $report['development']);
        $this->assertSame([], $report['problems']);
    }

    public function test_command_never_writes_to_env_file(): void
    {
        $this->applyProductionEnv();
        $this->setEnv('APP_ENV', 'local');

        $envPath = base_path('.env');

        $this->assertFileDoesNotExist($envPath, 'Precondition: .env should be masked for this test.');

        [$exitCode] = $this->runPreflight();

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist($envPath, 'The preflight command must never create a .env file.');
    }

    /**
     * Restore the real .env contents for tests that created it.
     */
    public function test_command_never_modifies_existing_env_file(): void
    {
        $this->applyProductionEnv();

        $envPath = base_path('.env');
        $sentinel = "# sentinel\nAPP_ENV=production\n";

        file_put_contents($envPath, $sentinel);
        $hashBefore = md5_file($envPath);
        $mtimeBefore = filemtime($envPath);

        [$exitCode] = $this->runPreflight();

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($envPath);
        $this->assertSame($hashBefore, md5_file($envPath));
        $this->assertSame($mtimeBefore, filemtime($envPath));

        unlink($envPath);
    }

    /**
     * @return array{0: int, 1: array{ok: bool, missing: list<string>, development: array<string, string>, problems: list<string>}}
     */
    private function runPreflight(): array
    {
        $this->app->offsetUnset(OutputStyle::class);

        $output = new BufferedOutput;

        $exitCode = $this->app->make(Kernel::class)->call('deploy:preflight-env', [], $output);

        return [$exitCode, $this->decodeJson($output->fetch())];
    }

    private function reloadEnvironment(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
    }

    private function applyProductionEnv(): void
    {
        foreach (self::PRODUCTION_ENV as $key => $value) {
            if (! in_array($key, $this->managedKeys, true)) {
                $this->managedKeys[] = $key;
            }

            $this->setEnv($key, $value);
        }
    }

    private function setEnv(string $key, string $value): void
    {
        if (! array_key_exists($key, $this->originalEnvValues)) {
            $this->originalEnvValues[$key] = getenv($key);
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function forgetEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    private function forgetManagedEnv(): void
    {
        foreach (array_unique($this->managedKeys) as $key) {
            // Restore the value that existed before this test mutated it.
            // Unsetting outright would delete shell-provided variables such as
            // DB_PASSWORD/DB_HOST, breaking subsequent Feature tests.
            if (array_key_exists($key, $this->originalEnvValues)) {
                $original = $this->originalEnvValues[$key];

                if ($original === false) {
                    $this->forgetEnv($key);
                } else {
                    $this->setEnv($key, $original);
                }

                continue;
            }

            $this->forgetEnv($key);
        }

        $this->managedKeys = [];
    }

    /**
     * @return array{ok: bool, missing: list<string>, development: array<string, string>, problems: list<string>}
     */
    private function decodeJson(string $output): array
    {
        $start = strpos($output, '{');
        $end = strrpos($output, '}');

        $this->assertNotFalse($start, "No JSON found in command output:\n{$output}");
        $this->assertNotFalse($end, "No JSON found in command output:\n{$output}");

        $decoded = json_decode(substr($output, $start, $end - $start + 1), true);

        $this->assertIsArray($decoded, "Command output is not valid JSON:\n{$output}");

        return $decoded;
    }

    private function exampleAppKey(): ?string
    {
        $path = base_path('.env.example');

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if (! str_starts_with($line, 'APP_KEY=')) {
                continue;
            }

            return trim(substr($line, strlen('APP_KEY=')), " \t\"'");
        }

        return null;
    }
}
