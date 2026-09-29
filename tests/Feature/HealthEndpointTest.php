<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_ok_json(): void
    {
        $response = $this->getJson('/health');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonStructure(['status', 'version', 'checks']);
        $response->assertJson([
            'status' => 'ok',
        ]);

        $this->assertSame('ok', $response->json('status'));
        $this->assertIsArray($response->json('checks'));
        $this->assertEqualsCanonicalizing(
            ['database', 'cache', 'disk'],
            array_keys($response->json('checks')),
        );
    }

    public function test_health_endpoint_returns_configured_version(): void
    {
        putenv('APP_VERSION=v1.2.3');
        $_ENV['APP_VERSION'] = 'v1.2.3';
        $_SERVER['APP_VERSION'] = 'v1.2.3';

        config(['app.version' => 'v1.2.3']);

        try {
            $response = $this->getJson('/health');

            $response->assertStatus(200);
            $this->assertSame('v1.2.3', $response->json('version'));
        } finally {
            putenv('APP_VERSION');
            unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
        }
    }

    public function test_health_endpoint_defaults_version_to_unknown(): void
    {
        // APP_VERSION must be absent from BOTH the process environment and the
        // config repository so the controller's default is exercised. We must
        // NOT overwrite config('app.version') here: doing so would inject the
        // very value under test (and setting it to null would shadow the
        // default, since an existing-but-null key skips config()'s fallback).
        $originalEnv = getenv('APP_VERSION');
        $originalEnvArray = $_ENV['APP_VERSION'] ?? null;
        $originalServer = $_SERVER['APP_VERSION'] ?? null;

        putenv('APP_VERSION');
        unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);

        // Rebuild config so app.version reflects the now-absent env var.
        $this->refreshApplication();
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        try {
            $response = $this->getJson('/health');

            $response->assertStatus(200);
            $this->assertSame('unknown', $response->json('version'));
        } finally {
            if ($originalEnv !== false) {
                putenv("APP_VERSION={$originalEnv}");
            }

            if ($originalEnvArray !== null) {
                $_ENV['APP_VERSION'] = $originalEnvArray;
            }

            if ($originalServer !== null) {
                $_SERVER['APP_VERSION'] = $originalServer;
            }
        }
    }
}
