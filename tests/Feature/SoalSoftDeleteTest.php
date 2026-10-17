<?php

namespace Tests\Feature;

use App\Models\Modul;
use App\Models\SoalMandiri;
use App\Models\SoalTp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SoalSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tables whose `deleted_at` column was created by this test (setUp),
     * so it can be removed again in tearDown when the permanent migration
     * (T04b) is not present yet.
     *
     * @var list<string>
     */
    private array $columnsCreatedByTest = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            (new SoalTp)->getTable(),
            (new SoalMandiri)->getTable(),
        ] as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn ($t) => $t->softDeletes());
                $this->columnsCreatedByTest[] = $table;
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->columnsCreatedByTest as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn ($t) => $t->dropColumn('deleted_at'));
            }
        }

        $this->columnsCreatedByTest = [];

        parent::tearDown();
    }

    public function test_soal_tp_uses_soft_deletes(): void
    {
        $this->assertSoftDeleteBehaviour(
            modelClass: SoalTp::class,
            table: (new SoalTp)->getTable(),
        );
    }

    public function test_soal_mandiri_uses_soft_deletes(): void
    {
        $this->assertSoftDeleteBehaviour(
            modelClass: SoalMandiri::class,
            table: (new SoalMandiri)->getTable(),
        );
    }

    public function test_soal_tp_soft_delete_state_is_controlled_by_soft_delete_api(): void
    {
        $modul = Modul::factory()->create();

        $soal = SoalTp::create([
            'modul_id' => $modul->id,
            'soal' => 'Soal TP untuk pengujian siklus soft delete.',
        ]);

        // A freshly created record starts untrashed, and only delete()/restore()
        // change the soft-delete state.
        $this->assertTrue($soal->exists);
        $this->assertNull($soal->getRawOriginal('deleted_at'));
        $this->assertNotSoftDeleted($soal);

        $soal->delete();
        $this->assertSoftDeleted($soal);
        $this->assertSame(0, SoalTp::count());

        $soal->restore();
        $this->assertNotSoftDeleted($soal);
        $this->assertSame(1, SoalTp::count());

        $soal->forceDelete();
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function assertSoftDeleteBehaviour(string $modelClass, string $table): void
    {
        $modul = Modul::factory()->create();

        $soal = $modelClass::create([
            'modul_id' => $modul->id,
            'soal' => 'Soal untuk pengujian soft delete.',
        ]);

        $id = $soal->getKey();

        $this->assertTrue(
            Schema::hasColumn($table, 'deleted_at'),
            "Kolom {$table}.deleted_at harus tersedia untuk pengujian soft delete.",
        );

        $totalBefore = $modelClass::withTrashed()->count();
        $this->assertSame(1, $modelClass::count());

        // Delete: row stays physically, deleted_at is filled.
        $soal->delete();

        $rawRow = $soal->getConnection()->table($table)->where('id', $id)->first();
        $this->assertNotNull($rawRow, "Baris {$table} masih ada secara fisik setelah delete.");
        $this->assertNotNull($rawRow->deleted_at, "Kolom {$table}.deleted_at harus terisi setelah delete.");
        $this->assertTrue($soal->trashed());

        // Default query hides the row; withTrashed still sees it.
        $this->assertSame(0, $modelClass::count(), 'count() berkurang 1 setelah delete.');
        $this->assertSame($totalBefore, $modelClass::withTrashed()->count(), 'withTrashed()->count() tidak berubah.');
        $this->assertNull($modelClass::find($id));
        $this->assertNotNull($modelClass::withTrashed()->find($id));
        $this->assertFalse($modelClass::query()->whereKey($id)->exists());

        // Restore: row returns to the default query.
        $this->assertTrue($soal->restore());

        $this->assertSame(1, $modelClass::count());
        $this->assertSame($totalBefore, $modelClass::withTrashed()->count());
        $this->assertNotNull($modelClass::find($id));
        $this->assertNotSoftDeleted($modelClass::find($id));

        $restoredRow = $soal->getConnection()->table($table)->where('id', $id)->first();
        $this->assertNull($restoredRow->deleted_at);

        // Cleanup the fixture row.
        $soal->forceDelete();
        $this->assertNull($modelClass::withTrashed()->find($id));
    }
}
