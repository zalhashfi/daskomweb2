<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes soal_tps / soal_mandiris soft-deletable and switches the answer
 * foreign keys from ON DELETE CASCADE to ON DELETE RESTRICT.
 *
 * With CASCADE, force-deleting a soal silently wipes every student answer.
 * RESTRICT refuses the hard delete; soft deletes only touch `deleted_at`,
 * so the normal SoalTp::delete() flow keeps working.
 */
return new class extends Migration
{
    /**
     * Answer table => [referenced soal table, foreign key name].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private array $answerForeignKeys = [
        'jawaban_tps' => ['soal_tps', 'jawaban_tps_soal_id_foreign'],
        'jawaban_mandiris' => ['soal_mandiris', 'jawaban_mandiris_soal_id_foreign'],
    ];

    private array $softDeleteTables = ['soal_tps', 'soal_mandiris'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->softDeleteTables as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->softDeletes();
                });
            }
        }

        foreach ($this->answerForeignKeys as $table => [$referenced, $foreignKey]) {
            $this->syncSoalForeignKey($table, $referenced, $foreignKey, 'restrict');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->answerForeignKeys as $table => [$referenced, $foreignKey]) {
            $this->syncSoalForeignKey($table, $referenced, $foreignKey, 'cascade');
        }

        foreach ($this->softDeleteTables as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('deleted_at');
                });
            }
        }
    }

    /**
     * Recreate the soal FK with the requested delete rule.
     *
     * The FK is always dropped first: MySQL does not let ALTER an existing
     * constraint, and this keeps the call idempotent when the rule already
     * matches. Dropping an InnoDB FK never drops its backing index, so the
     * `soal_id` index (created by the original create-table migration) is
     * reused when the constraint is added back.
     *
     * @param  'restrict'|'cascade'  $onDelete
     */
    private function syncSoalForeignKey(string $table, string $referenced, string $foreignKey, string $onDelete): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable($referenced) || ! Schema::hasColumn($table, 'soal_id')) {
            return;
        }

        // Laravel's hasForeignKey() cannot check a constraint by name, so the
        // rule is re-applied whenever the named constraint is present.
        if ($this->foreignKeyExists($table, $foreignKey)) {
            Schema::table($table, function (Blueprint $blueprint) use ($foreignKey) {
                $blueprint->dropForeign($foreignKey);
            });
        }

        $rule = $onDelete === 'cascade' ? 'cascade' : 'restrict';

        Schema::table($table, function (Blueprint $blueprint) use ($referenced, $foreignKey, $rule) {
            $blueprint->foreign('soal_id', $foreignKey)
                ->references('id')
                ->on($referenced)
                ->onUpdate('restrict')
                ->onDelete($rule);
        });
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return DB::selectOne(
                'select constraint_name from information_schema.REFERENTIAL_CONSTRAINTS
                 where constraint_schema = database() and table_name = ? and constraint_name = ?',
                [$table, $foreignKey]
            ) !== null;
        }

        // Other drivers (sqlite tests) fall back to Laravel's column-level check.
        return Schema::hasForeignKey($table, 'soal_id');
    }
};
