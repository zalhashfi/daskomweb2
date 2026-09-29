<?php

namespace Tests\Feature;

use App\Http\Controllers\API\AutosaveSnapshotController;
use App\Models\Kelas;
use App\Models\Modul;
use App\Models\Praktikan;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SnapshotCacheStoreTest extends TestCase
{
    use RefreshDatabase;

    private Praktikan $praktikan;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('array')->flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::create([
            'name' => 'praktikan-tester',
            'guard_name' => 'praktikan',
        ]);

        $permission = Permission::firstOrCreate([
            'name' => 'praktikum-lms',
            'guard_name' => 'praktikan',
        ]);

        $role->givePermissionTo($permission);

        $kelas = Kelas::factory()->create();
        $this->praktikan = Praktikan::factory()->create([
            'kelas_id' => $kelas->id,
        ]);

        $this->praktikan->assignRole($role);
        $this->actingAs($this->praktikan, 'praktikan');
    }

    public function test_snapshot_store_config_defaults_to_redis(): void
    {
        $this->assertSame('redis', config('cache.snapshot_store'));
    }

    public function test_snapshot_store_config_honours_override(): void
    {
        config(['cache.snapshot_store' => 'array']);

        $this->assertSame('array', config('cache.snapshot_store'));
    }

    public function test_snapshot_store_config_is_present_in_config_file(): void
    {
        $cacheConfig = require base_path('config/cache.php');

        $this->assertArrayHasKey('snapshot_store', $cacheConfig);
        $this->assertSame('redis', $cacheConfig['snapshot_store']);
    }

    public function test_controller_resolves_cache_store_from_config(): void
    {
        config(['cache.snapshot_store' => 'array']);

        $modul = Modul::factory()->create();

        $this->postJson('/api-v1/praktikan/autosave', [
            'praktikan_id' => $this->praktikan->id,
            'modul_id' => $modul->id,
            'tipe_soal' => 'ta',
            'jawaban' => ['101' => 3],
        ])
            ->assertOk()
            ->assertJsonFragment(['success' => true]);

        $key = sprintf(
            'autosave_snapshot:%d:%d:ta',
            $this->praktikan->id,
            $modul->id
        );

        $this->assertTrue(
            Cache::store('array')->has($key),
            'Expected the snapshot write to be visible in the "array" store.'
        );

        $snapshot = Cache::store('array')->get($key);

        $this->assertIsArray($snapshot);
        $this->assertSame($this->praktikan->id, $snapshot['praktikan_id']);
        $this->assertSame($modul->id, $snapshot['modul_id']);
        $this->assertSame(['101' => 3], $snapshot['jawaban']);
    }

    public function test_controller_tracks_the_configured_store(): void
    {
        config(['cache.snapshot_store' => 'array']);

        $controller = app(AutosaveSnapshotController::class);

        $property = new \ReflectionProperty($controller, 'cacheStore');
        $property->setAccessible(true);

        $this->assertSame('array', $property->getValue($controller));

        $method = new \ReflectionMethod($controller, 'cache');
        $method->setAccessible(true);

        $cache = $method->invoke($controller);

        $this->assertInstanceOf(Repository::class, $cache);
        $this->assertInstanceOf(ArrayStore::class, $cache->getStore());
    }

    public function test_controller_uses_a_non_array_store_when_configured(): void
    {
        config(['cache.snapshot_store' => 'null']);

        $controller = app(AutosaveSnapshotController::class);

        $property = new \ReflectionProperty($controller, 'cacheStore');
        $property->setAccessible(true);

        $this->assertSame('null', $property->getValue($controller));

        $modul = Modul::factory()->create();

        $this->postJson('/api-v1/praktikan/autosave', [
            'praktikan_id' => $this->praktikan->id,
            'modul_id' => $modul->id,
            'tipe_soal' => 'ta',
            'jawaban' => ['101' => 3],
        ])->assertOk();

        $key = sprintf(
            'autosave_snapshot:%d:%d:ta',
            $this->praktikan->id,
            $modul->id
        );

        $this->assertFalse(
            Cache::store('array')->has($key),
            'Writes must not land in the "array" store when a different store is configured.'
        );
    }
}
