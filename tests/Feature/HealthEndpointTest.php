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
        $this->assertSame([], $response->json('checks'));
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
        putenv('APP_VERSION');
        unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);

        config(['app.version' => env('APP_VERSION', 'unknown')]);

        $response = $this->getJson('/health');

        $response->assertStatus(200);
        $this->assertSame('unknown', $response->json('version'));
    }
}
