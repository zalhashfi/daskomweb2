<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links every jawaban_mandiris row to a jawaban_submissions attempt row
 * (tipe='tm').
 *
 * Mirrors 2026_10_17_150000_add_submission_id_to_jawaban_tps_table: legacy
 * jawaban_mandiris rows carried no submission pointer, so the migration
 * synthesises exactly one submission per distinct (praktikan_id, modul_id)
 * pair (attempt_no = 1, jawaban_id = NULL, created_at = earliest answer
 * timestamp, falling back to now() when every row has a null created_at) and
 * then stamps that id onto every matching answer row.
 *
 * The backfill is idempotent (insertOrIgnore), so the migration can be
 * re-applied on a partially migrated database. down() only removes the schema
 * pieces; the synthesised submission rows are kept because other features
 * already reference jawaban_submissions.
 */
return new class extends Migration
{
    private const TABLE = 'jawaban_mandiris';

    private const SUBMISSIONS = 'jawaban_submissions';

    private const TIPE = 'tm';

    private const FOREIGN_KEY = 'jawaban_mandiris_submission_id_foreign';

    private const INDEX = 'jawaban_mandiris_submission_id_index';

    private const UNIQUE = 'jawaban_mandiris_submission_id_soal_id_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Nullable placeholder column.
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->after('modul_id');
        });

        // 2 + 3. Synthesise one tm submission per unique (praktikan_id,
        // modul_id) pair and point the answer rows at it.
        $this->backfillSubmissions();

        // 4. Every answer row now belongs to a submission.
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable(false)->change();
        });

        // 5. Cascade with the submission. A plain index backs the foreign key
        // so the composite unique below is not MySQL's implicit FK index
        // (that one cannot be dropped while the FK exists).
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->index('submission_id', self::INDEX);
            $table->foreign('submission_id', self::FOREIGN_KEY)
                ->references('id')
                ->on(self::SUBMISSIONS)
                ->cascadeOnDelete();
        });

        // 6. One answer per question within a submission.
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unique(['submission_id', 'soal_id'], self::UNIQUE);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, 'submission_id')) {
            $this->dropUniqueIfExists();
            $this->dropForeignKeyIfExists();
            $this->dropIndexIfExists();

            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn('submission_id');
            });
        }
    }

    private function dropUniqueIfExists(): void
    {
        if ($this->indexExists(self::UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(self::UNIQUE);
            });
        }
    }

    private function dropForeignKeyIfExists(): void
    {
        if ($this->foreignKeyExists()) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropForeign(self::FOREIGN_KEY);
            });
        }
    }

    private function dropIndexIfExists(): void
    {
        if ($this->indexExists(self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
    }

    private function indexExists(string $index): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [self::TABLE, $index]
        );

        return $rows !== [];
    }

    private function foreignKeyExists(): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [self::TABLE, self::FOREIGN_KEY]
        );

        return $rows !== [];
    }

    /**
     * Collect each distinct (praktikan_id, modul_id) pair with the earliest
     * created_at among its answer rows.
     *
     * Deliberately not a `GROUP BY praktikan_id, modul_id` query: folding the
     * rows in PHP keeps the result independent of MySQL's loose-index-scan /
     * temporary-table plans and lets a null created_at be ignored instead of
     * making MIN() ambiguous.
     *
     * @return list<array{praktikan_id:int, modul_id:int, first_created_at:mixed}>
     */
    private function uniquePairsWithEarliestTimestamp($connection): array
    {
        /** @var array<string, array{praktikan_id:int, modul_id:int, first_created_at:mixed}> $pairs */
        $pairs = [];

        $rows = $connection->table(self::TABLE)
            ->select('praktikan_id', 'modul_id', 'created_at')
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            $key = $row->praktikan_id.':'.$row->modul_id;
            $current = $pairs[$key]['first_created_at'] ?? null;

            if (isset($pairs[$key]) && ! $this->isEarlier($row->created_at, $current)) {
                continue;
            }

            $pairs[$key] = [
                'praktikan_id' => $row->praktikan_id,
                'modul_id' => $row->modul_id,
                'first_created_at' => $row->created_at ?? $current,
            ];
        }

        return array_values($pairs);
    }

    /**
     * True when $candidate is a strictly earlier non-null timestamp than
     * $current (a null $current means "no candidate recorded yet").
     */
    private function isEarlier(?string $candidate, ?string $current): bool
    {
        if ($candidate === null) {
            return false;
        }

        return $current === null || strtotime($candidate) < strtotime($current);
    }

    /**
     * Create the missing tm submissions and stamp their id onto
     * jawaban_mandiris.
     */
    private function backfillSubmissions(): void
    {
        $connection = DB::connection();

        $pairs = $this->uniquePairsWithEarliestTimestamp($connection);

        $now = now();

        // insertOrIgnore keys on the unique (praktikan_id, modul_id, tipe,
        // attempt_no) slot only: it creates the submission when missing and is
        // a no-op on a re-run. created_at is intentionally NOT part of the
        // match key. The tipe filter keeps 'tm' separated from the tp/praktikum
        // submissions of the same modul.
        foreach ($pairs as $pair) {
            $createdAt = $pair['first_created_at'] ?? $now;

            $connection->table(self::SUBMISSIONS)->insertOrIgnore([
                'praktikan_id' => $pair['praktikan_id'],
                'modul_id' => $pair['modul_id'],
                'tipe' => self::TIPE,
                'attempt_no' => 1,
                'jawaban_id' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        // One UPDATE ... JOIN keeps the stamping idempotent and avoids a query
        // per answer row. Only rows still holding NULL are touched.
        $connection->statement(
            'UPDATE '.self::TABLE.' AS jm'
            .' INNER JOIN '.self::SUBMISSIONS.' AS js'
            .' ON js.praktikan_id = jm.praktikan_id'
            .' AND js.modul_id = jm.modul_id'
            .' AND js.tipe = ?'
            .' AND js.attempt_no = 1'
            .' SET jm.submission_id = js.id'
            .' WHERE jm.submission_id IS NULL',
            [self::TIPE]
        );
    }
};
