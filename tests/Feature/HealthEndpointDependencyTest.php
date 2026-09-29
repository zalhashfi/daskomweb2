<?php

namespace Tests\Feature;

use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class HealthEndpointDependencyTest extends TestCase
{
    public function test_health_endpoint_reports_all_dependencies_ok(): void
    {
        $response = $this->getJson('/health');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'ok',
            'checks' => [
                'database' => ['status' => 'ok'],
                'cache' => ['status' => 'ok'],
                'disk' => ['status' => 'ok'],
            ],
        ]);

        $this->assertSame('ok', $response->json('checks.database.status'));
        $this->assertSame('ok', $response->json('checks.cache.status'));
        $this->assertSame('ok', $response->json('checks.disk.status'));
    }

    public function test_health_endpoint_returns_503_when_cache_fails(): void
    {
        $this->bindBrokenCacheStore();

        $response = $this->getJson('/health');

        $response->assertStatus(503);
        $this->assertNotSame('ok', $response->json('status'));
        $this->assertSame('fail', $response->json('checks.cache.status'));
        $this->assertNotNull($response->json('checks.cache.error'));
        $this->assertSame('ok', $response->json('checks.database.status'));
    }

    public function test_health_endpoint_returns_503_when_database_fails(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->andThrow(new RuntimeException('database is unreachable'));

        $response = $this->getJson('/health');

        $response->assertStatus(503);
        $this->assertNotSame('ok', $response->json('status'));
        $this->assertSame('fail', $response->json('checks.database.status'));
        $this->assertNotNull($response->json('checks.database.error'));
    }

    public function test_health_endpoint_returns_degraded_when_disk_fails(): void
    {
        // A path nested under a regular file can never be a directory, so the
        // local disk fails to initialise / write and the check reports failure.
        config(['filesystems.disks.local.root' => base_path('composer.json').'/health']);

        $response = $this->getJson('/health');

        $response->assertStatus(200);
        $this->assertSame('degraded', $response->json('status'));
        $this->assertSame('fail', $response->json('checks.disk.status'));
        $this->assertNotNull($response->json('checks.disk.error'));
        $this->assertSame('ok', $response->json('checks.database.status'));
        $this->assertSame('ok', $response->json('checks.cache.status'));
    }

    private function bindBrokenCacheStore(): void
    {
        config([
            'cache.stores.broken' => [
                'driver' => 'array',
            ],
        ]);

        $store = Mockery::mock(Store::class);
        $store->shouldReceive('get')->andThrow(new RuntimeException('cache store is down'));
        $store->shouldReceive('put')->andThrow(new RuntimeException('cache store is down'));
        $store->shouldReceive('putMany')->andThrow(new RuntimeException('cache store is down'));
        $store->shouldReceive('forget')->andThrow(new RuntimeException('cache store is down'));
        $store->shouldReceive('flush')->andThrow(new RuntimeException('cache store is down'));
        $store->shouldReceive('getPrefix')->andReturn('broken');

        Cache::store('broken')->setStore($store);

        config(['cache.default' => 'broken']);
    }
}
