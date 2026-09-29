<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The table that holds the randomization configuration.
     */
    protected string $table = 'question_randomization_configs';

    /**
     * The generated name Laravel gives the composite unique index on
     * ['modul_id', 'category'].
     */
    protected string $uniqueIndex = 'question_randomization_configs_modul_id_category_unique';

    public function up(): void
    {
        if (! Schema::hasTable($this->table)) {
            return;
        }

        // This migration may run on a database where the "create" migration
        // (150434) already added every column. Guard each column individually
        // so partial states are handled too.
        $missing = $this->missingColumns([
            'category',
            'easy_count',
            'medium_count',
            'hard_count',
            'enabled',
        ]);

        if ($missing !== []) {
            Schema::table($this->table, function (Blueprint $table) use ($missing) {
                // Column placement for a fresh table (column absent entirely).
                if (in_array('category', $missing, true)) {
                    $table->string('category', 10)->after('modul_id');
                }
                if (in_array('easy_count', $missing, true)) {
                    $table->unsignedSmallInteger('easy_count')->default(5)->after('category');
                }
                if (in_array('medium_count', $missing, true)) {
                    $table->unsignedSmallInteger('medium_count')->default(4)->after('easy_count');
                }
                if (in_array('hard_count', $missing, true)) {
                    $table->unsignedSmallInteger('hard_count')->default(1)->after('medium_count');
                }
                if (in_array('enabled', $missing, true)) {
                    $table->boolean('enabled')->default(false)->after('hard_count');
                }
            });
        }

        // The unique index is guarded separately: the "create" migration
        // already declares it, so creating it again would throw.
        if (! $this->hasUniqueIndex()) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->unique(['modul_id', 'category']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable($this->table)) {
            return;
        }

        // Tolerate a partially-applied state: only drop what actually exists.
        if ($this->hasUniqueIndex()) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropUnique($this->uniqueIndex);
            });
        }

        $existing = $this->existingColumns([
            'category',
            'easy_count',
            'medium_count',
            'hard_count',
            'enabled',
        ]);

        if ($existing !== []) {
            Schema::table($this->table, function (Blueprint $table) use ($existing) {
                $table->dropColumn($existing);
            });
        }
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    protected function missingColumns(array $columns): array
    {
        return array_values(array_filter(
            $columns,
            fn (string $column): bool => ! Schema::hasColumn($this->table, $column),
        ));
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    protected function existingColumns(array $columns): array
    {
        return array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($this->table, $column),
        ));
    }

    /**
     * Driver-safe existence check for the composite unique index.
     *
     * MySQL/MariaDB are introspected directly through information_schema.statistics
     * scoped to database() (the current schema), so no database name is ever
     * hardcoded. Other drivers fall back to Laravel's Schema::getIndexes().
     *
     * The index is matched by generated name first, then by its column set so a
     * differently-named unique index on the same columns is still recognised.
     */
    protected function hasUniqueIndex(): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $result = DB::selectOne(
                'select count(*) as aggregate from information_schema.statistics
                 where table_schema = database()
                   and table_name = ?
                   and index_name = ?',
                [$this->table, $this->uniqueIndex],
            );

            if ((int) ($result->aggregate ?? 0) > 0) {
                return true;
            }
        }

        return $this->indexCoversColumns();
    }

    /**
     * Whether any unique index on the table already covers exactly the
     * ['modul_id', 'category'] column set.
     */
    protected function indexCoversColumns(): bool
    {
        $columns = ['modul_id', 'category'];

        foreach (Schema::getIndexes($this->table) as $index) {
            if (! ($index['unique'] ?? false)) {
                continue;
            }

            if (array_values($index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }
};
