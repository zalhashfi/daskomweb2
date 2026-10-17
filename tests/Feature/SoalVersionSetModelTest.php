<?php

namespace Tests\Feature;

use App\Models\Modul;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers the soal version schema (2026_10_17_130000_create_soal_version_tables)
 * and the SoalVersionSet model (T14).
 *
 * RefreshDatabase runs every migration, so the post-up() shape is the start;
 * down()/up() are re-driven by loading the migration file, mirroring
 * LegacyMigrationRollbackTest.
 */
class SoalVersionSetModelTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_17_130000_create_soal_version_tables';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    public function test_schema_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('soal_version_sets'));
        $this->assertTrue(Schema::hasTable('soal_version_items'));

        foreach ([
            'id',
            'modul_id',
            'version_no',
            'status',
            'created_by_asisten_id',
            'approved_by_type',
            'approved_by_id',
            'bobot_total',
            'activated_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('soal_version_sets', $column),
                "Kolom soal_version_sets.{$column} harus ada."
            );
        }

        foreach ([
            'id',
            'version_set_id',
            'soal_type',
            'soal_id',
            'bobot',
            'max_score',
            'teks_snapshot',
            'jawaban_standar',
            'jawaban_standar_status',
            'jawaban_standar_approved_by',
            'jawaban_standar_approved_at',
            'reject_reason',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('soal_version_items', $column),
                "Kolom soal_version_items.{$column} harus ada."
            );
        }
    }

    public function test_set_with_three_items_totalling_100_is_stored(): void
    {
        $modul = Modul::factory()->create();
        $set = $this->makeSet($modul, 1);
        $this->makeItems($set, [33, 33, 34]);

        $sum = DB::table('soal_version_items')
            ->where('version_set_id', $set->id)
            ->sum('bobot');

        $this->assertSame(100, (int) $sum, 'Total bobot satu set harus 100.');
        $this->assertSame(3, DB::table('soal_version_items')->where('version_set_id', $set->id)->count());
    }

    public function test_items_relation_returns_all_items(): void
    {
        $modul = Modul::factory()->create();
        $set = $this->makeSet($modul, 1);
        $this->makeItems($set, [33, 33, 34]);

        $items = $set->fresh()->items;

        $this->assertCount(3, $items);
        $this->assertInstanceOf(SoalVersionItem::class, $items->first());
        $this->assertSame([33, 33, 34], $items->pluck('bobot')->all());
    }

    public function test_active_scope_only_returns_activated_sets(): void
    {
        $modul = Modul::factory()->create();
        $inactive = $this->makeSet($modul, 1);
        $active = $this->makeSet($modul, 2, ['activated_at' => '2026-10-17 10:00:00']);
        $otherInactive = $this->makeSet(Modul::factory()->create(), 1);

        $ids = SoalVersionSet::active()->pluck('id')->all();

        $this->assertSame([$active->id], $ids);
        $this->assertNotContains($inactive->id, $ids, 'Set tanpa activated_at tidak boleh aktif.');
        $this->assertNotContains($otherInactive->id, $ids);
        $this->assertFalse($inactive->fresh()->isActive());
        $this->assertTrue($active->fresh()->isActive());
        $this->assertInstanceOf(\Carbon\Carbon::class, $active->fresh()->activated_at);
    }

    public function test_activating_a_set_moves_it_into_active_scope(): void
    {
        $modul = Modul::factory()->create();
        $set = $this->makeSet($modul, 1);

        $this->assertSame(0, SoalVersionSet::active()->count());

        $set->update(['activated_at' => now()]);

        $this->assertSame(1, SoalVersionSet::active()->count());
        $this->assertTrue(SoalVersionSet::active()->whereKey($set->id)->exists());
    }

    public function test_modul_relation_resolves_the_owner_modul(): void
    {
        $modul = Modul::factory()->create();
        $set = $this->makeSet($modul, 1)->fresh();

        $this->assertInstanceOf(Modul::class, $set->modul);
        $this->assertSame($modul->id, $set->modul->id);
    }

    public function test_duplicate_modul_version_is_rejected_by_unique_index(): void
    {
        $modul = Modul::factory()->create();

        SoalVersionSet::create(['modul_id' => $modul->id, 'version_no' => 1]);

        $exception = $this->captureQueryException(
            fn () => SoalVersionSet::create(['modul_id' => $modul->id, 'version_no' => 1])
        );

        $this->assertSame('23000', $exception->getCode(), 'SQLSTATE harus menandakan unique violation.');
        $this->assertStringContainsString('1062', $exception->getMessage(), 'MySQL harus mengembalikan error 1062.');
        $this->assertSame(1, SoalVersionSet::query()->count());
    }

    public function test_same_version_on_different_modul_is_allowed(): void
    {
        $setA = $this->makeSet(Modul::factory()->create(), 1);
        $setB = $this->makeSet(Modul::factory()->create(), 1);

        $this->assertNotSame($setA->id, $setB->id);
        $this->assertSame(2, SoalVersionSet::query()->count());
    }

    public function test_duplicate_item_slot_is_rejected_by_unique_index(): void
    {
        $set = $this->makeSet(Modul::factory()->create(), 1);

        SoalVersionItem::create([
            'version_set_id' => $set->id,
            'soal_type' => SoalVersionItem::SOAL_TP,
            'soal_id' => 10,
            'bobot' => 50,
        ]);

        $exception = $this->captureQueryException(
            fn () => SoalVersionItem::create([
                'version_set_id' => $set->id,
                'soal_type' => SoalVersionItem::SOAL_TP,
                'soal_id' => 10,
                'bobot' => 50,
            ])
        );

        $this->assertSame('23000', $exception->getCode());
        $this->assertStringContainsString('1062', $exception->getMessage());
        $this->assertSame(1, SoalVersionItem::query()->count());
    }

    public function test_deleting_version_set_cascades_items(): void
    {
        if (! $this->isMySqlFamily()) {
            $this->markTestSkipped('Cascade assertions are MySQL/MariaDB specific.');
        }

        $set = $this->makeSet(Modul::factory()->create(), 1);
        $this->makeItems($set, [33, 33, 34]);

        DB::table('soal_version_sets')->where('id', $set->id)->delete();

        $this->assertSame(0, DB::table('soal_version_items')->where('version_set_id', $set->id)->count());
    }

    public function test_migration_down_then_up_restores_tables(): void
    {
        $set = $this->makeSet(Modul::factory()->create(), 1);
        $this->makeItems($set, [33, 33, 34]);

        $this->assertTrue(Schema::hasTable('soal_version_sets'));
        $this->assertTrue(Schema::hasTable('soal_version_items'));

        // jawaban_submissions.version_set_id (T23a) references soal_version_sets,
        // so its migration must be reverted first or MySQL refuses the drop.
        $bindingDown = $this->loadMigration('2026_10_17_250000_add_version_set_id_to_jawaban_submissions_table');
        $bindingDown->down();

        $this->runMigration('down');

        $this->assertFalse(Schema::hasTable('soal_version_items'), 'down() harus menghapus soal_version_items.');
        $this->assertFalse(Schema::hasTable('soal_version_sets'), 'down() harus menghapus soal_version_sets.');
        $this->assertTrue(Schema::hasTable('moduls'), 'down() tidak boleh menyentuh tabel lain.');

        $this->runMigration('up');

        $this->loadMigration('2026_10_17_250000_add_version_set_id_to_jawaban_submissions_table')->up();

        $this->assertTrue(Schema::hasTable('soal_version_sets'), 'up() harus membuat ulang tabel sets.');
        $this->assertTrue(Schema::hasTable('soal_version_items'), 'up() harus membuat ulang tabel items.');

        foreach (['modul_id', 'version_no', 'status', 'activated_at', 'bobot_total'] as $column) {
            $this->assertTrue(Schema::hasColumn('soal_version_sets', $column), "up() harus membuat kembali {$column}.");
        }
        foreach (['version_set_id', 'soal_type', 'soal_id', 'bobot', 'max_score', 'teks_snapshot'] as $column) {
            $this->assertTrue(Schema::hasColumn('soal_version_items', $column), "up() harus membuat kembali {$column}.");
        }

        $rebuilt = $this->makeSet(Modul::factory()->create(), 1);
        $this->makeItems($rebuilt, [40, 60]);

        $this->assertSame(
            100,
            (int) DB::table('soal_version_items')->where('version_set_id', $rebuilt->id)->sum('bobot')
        );
        $this->assertSame([40, 60], $rebuilt->fresh()->items->pluck('bobot')->all());
    }

    private function isMySqlFamily(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSet(Modul $modul, int $versionNo, array $attributes = []): SoalVersionSet
    {
        return SoalVersionSet::create(array_merge([
            'modul_id' => $modul->id,
            'version_no' => $versionNo,
        ], $attributes))->fresh();
    }

    /**
     * @param  list<int>  $bobot
     * @return list<SoalVersionItem>
     */
    private function makeItems(SoalVersionSet $set, array $bobot): array
    {
        $items = [];

        foreach (array_values($bobot) as $index => $weight) {
            $items[] = SoalVersionItem::create([
                'version_set_id' => $set->id,
                'soal_type' => SoalVersionItem::SOAL_TP,
                'soal_id' => 1000 + $index,
                'bobot' => $weight,
                'max_score' => 100,
                'teks_snapshot' => 'Soal ' . ($index + 1),
            ]);
        }

        return $items;
    }

    private function captureQueryException(callable $callback): QueryException
    {
        try {
            $callback();
        } catch (QueryException $exception) {
            return $exception;
        }

        $this->fail('Expected the database to reject the duplicate row.');
    }

    private function loadMigration(string $name): object
    {
        $path = collect(glob(database_path('migrations/*.php')))
            ->first(fn (string $file): bool => str_contains(basename($file), $name));

        $this->assertNotNull($path, "Migration {$name} not found.");

        return require $path;
    }

    private function runMigration(string $direction): void
    {
        $path = collect(glob(database_path('migrations/*.php')))
            ->first(fn (string $file): bool => str_contains(basename($file), self::MIGRATION));

        $this->assertNotNull($path, 'Migration '.self::MIGRATION.' not found.');

        $migration = require $path;
        $migration->{$direction}();
    }
}
