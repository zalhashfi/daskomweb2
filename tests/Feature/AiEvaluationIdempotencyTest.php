<?php

namespace Tests\Feature;

use App\Models\AiEvaluation;
use App\Models\AiEvaluationAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers the AI evaluation schema (2026_10_17_210000_create_ai_evaluations_tables)
 * and the AiEvaluation / AiEvaluationAttempt models (T20).
 *
 * The schema focus is idempotency: (jawaban_id, answer_hash, version_set_id,
 * ai_config_id) must be unique, including when version_set_id/ai_config_id are
 * absent — the case MySQL's NULL-distinct unique index would let through.
 *
 * RefreshDatabase runs every migration, so the post-up() shape is the start;
 * down()/up() are re-driven by loading the migration file, mirroring
 * AiConfigTest.
 */
class AiEvaluationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_17_210000_create_ai_evaluations_tables';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    // ---------------------------------------------------------------------
    // Schema
    // ---------------------------------------------------------------------

    public function test_schema_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('ai_evaluations'));
        $this->assertTrue(Schema::hasTable('ai_evaluation_attempts'));

        foreach ([
            'id',
            'jawaban_id',
            'jawaban_type',
            'answer_hash',
            'version_set_id',
            'ai_config_id',
            'status',
            'ai_score',
            'max_score',
            'raw_response',
            'driver',
            'response_model',
            'model_drift',
            'tokens_in',
            'tokens_out',
            'latency_ms',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('ai_evaluations', $column),
                "Kolom ai_evaluations.{$column} harus ada."
            );
        }

        foreach ([
            'id',
            'ai_evaluation_id',
            'attempt_no',
            'request_hash',
            'http_status',
            'outcome',
            'response_model',
            'tokens_in',
            'tokens_out',
            'latency_ms',
            'error_code',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('ai_evaluation_attempts', $column),
                "Kolom ai_evaluation_attempts.{$column} harus ada."
            );
        }

        $indexes = collect(Schema::getIndexes('ai_evaluations'));
        $this->assertTrue(
            $indexes->contains(fn (array $index): bool => $index['columns'] === [
                'jawaban_id',
                'answer_hash',
                'version_set_id',
                'ai_config_id',
            ] && $index['unique']),
            'ai_evaluations harus punya unique index atas kunci idempotensi.'
        );
    }

    public function test_defaults_come_from_migration(): void
    {
        $row = DB::table('ai_evaluations')->where('id', $this->insertEvaluation())->first();

        $this->assertSame('pending', $row->status);
        $this->assertSame(0, (int) $row->model_drift, 'model_drift harus default false.');
        $this->assertSame(0, (int) $row->tokens_in);
        $this->assertSame(0, (int) $row->tokens_out);
        $this->assertNull($row->ai_score);
        $this->assertNull($row->max_score);
        $this->assertNull($row->raw_response);
        $this->assertNull($row->driver);
        $this->assertNull($row->response_model);
        $this->assertNull($row->latency_ms);
    }

    public function test_fresh_model_has_model_drift_false(): void
    {
        $evaluation = AiEvaluation::query()->create($this->evaluationAttributes())->fresh();

        $this->assertFalse($evaluation->model_drift);
        $this->assertIsBool($evaluation->model_drift);
    }

    // ---------------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------------

    public function test_duplicate_idempotency_key_is_rejected_by_unique_index(): void
    {
        $attributes = $this->evaluationAttributes();

        AiEvaluation::query()->create($attributes);

        $exception = $this->captureQueryException(
            fn () => AiEvaluation::query()->create($attributes)
        );

        $this->assertSame('23000', $exception->getCode(), 'SQLSTATE harus menandakan unique violation.');
        $this->assertStringContainsString('1062', $exception->getMessage(), 'MySQL harus mengembalikan error 1062.');
        $this->assertSame(1, AiEvaluation::query()->count(), 'Kunci duplikat tidak boleh menambah baris.');
    }

    public function test_duplicate_idempotency_key_without_version_set_and_config_is_rejected(): void
    {
        $attributes = $this->evaluationAttributes([
            'version_set_id' => null,
            'ai_config_id' => null,
        ]);

        AiEvaluation::query()->create($attributes);

        $exception = $this->captureQueryException(
            fn () => AiEvaluation::query()->create($attributes)
        );

        $this->assertSame('23000', $exception->getCode());
        $this->assertStringContainsString('1062', $exception->getMessage());
        $this->assertSame(1, AiEvaluation::query()->count());

        // NULL/0 both mean "none"? They must resolve to the same stored key.
        $exception = $this->captureQueryException(
            fn () => AiEvaluation::query()->create($this->evaluationAttributes([
                'version_set_id' => 0,
                'ai_config_id' => 0,
            ]))
        );

        $this->assertSame('23000', $exception->getCode(), 'NULL dan 0 harus dianggap kunci yang sama.');
        $this->assertSame(1, AiEvaluation::query()->count());
    }

    public function test_different_answer_hash_is_allowed(): void
    {
        AiEvaluation::query()->create($this->evaluationAttributes());
        AiEvaluation::query()->create($this->evaluationAttributes(['answer_hash' => hash('sha256', 'jawaban lain')]));

        $this->assertSame(2, AiEvaluation::query()->count());
    }

    public function test_different_config_is_allowed_for_same_answer(): void
    {
        AiEvaluation::query()->create($this->evaluationAttributes());
        AiEvaluation::query()->create($this->evaluationAttributes(['ai_config_id' => 987654]));

        $this->assertSame(2, AiEvaluation::query()->count());
    }

    // ---------------------------------------------------------------------
    // Relations
    // ---------------------------------------------------------------------

    public function test_evaluation_has_many_attempts(): void
    {
        $evaluation = AiEvaluation::query()->create($this->evaluationAttributes([
            'jawaban_type' => 'tm',
            'status' => AiEvaluation::STATUS_FAILED,
        ]));

        $first = $evaluation->attempts()->create([
            'attempt_no' => 1,
            'request_hash' => hash('sha256', 'request-1'),
            'http_status' => 503,
            'outcome' => 'retry',
            'error_code' => 'E_PROVIDER_DOWN',
            'latency_ms' => 1200,
        ]);
        $second = $evaluation->attempts()->create([
            'attempt_no' => 2,
            'request_hash' => hash('sha256', 'request-2'),
            'http_status' => 200,
            'outcome' => 'done',
            'response_model' => 'gpt-4o-mini',
            'tokens_in' => 320,
            'tokens_out' => 45,
            'latency_ms' => 900,
        ]);

        $this->assertSame(2, $evaluation->attempts()->count());
        $this->assertInstanceOf(AiEvaluationAttempt::class, $first);
        $this->assertSame([1, 2], $evaluation->fresh()->attempts->pluck('attempt_no')->all());
        $this->assertSame(
            $evaluation->getKey(),
            $second->evaluation()->first()->getKey(),
            'attempt harus menunjuk balik ke evaluasinya.'
        );
        $this->assertSame(320, $evaluation->fresh()->attempts->last()->tokens_in);
    }

    public function test_deleting_evaluation_cascades_attempts(): void
    {
        $evaluation = AiEvaluation::query()->create($this->evaluationAttributes());
        $evaluation->attempts()->create(['attempt_no' => 1, 'outcome' => 'done']);

        $evaluation->delete();

        $this->assertSame(0, DB::table('ai_evaluation_attempts')->count());
    }

    public function test_many_evaluations_keep_their_own_attempts(): void
    {
        $tp = AiEvaluation::query()->create($this->evaluationAttributes(['jawaban_type' => 'tp']));
        $tm = AiEvaluation::query()->create($this->evaluationAttributes([
            'jawaban_type' => 'tm',
            'jawaban_id' => 2,
        ]));

        $tp->attempts()->create(['attempt_no' => 1, 'outcome' => 'done']);
        $tp->attempts()->create(['attempt_no' => 2, 'outcome' => 'done']);
        $tm->attempts()->create(['attempt_no' => 1, 'outcome' => 'failed']);

        $this->assertSame(2, $tp->attempts()->count());
        $this->assertSame(1, $tm->attempts()->count());
        $this->assertSame('tp', $tp->jawaban_type);
        $this->assertSame('tm', $tm->jawaban_type);
    }

    // ---------------------------------------------------------------------
    // isTerminal() / hidden
    // ---------------------------------------------------------------------

    public function test_is_terminal_is_true_for_every_terminal_status(): void
    {
        foreach (['done', 'failed', 'manual_required', 'key_pending'] as $status) {
            $evaluation = new AiEvaluation(['status' => $status]);

            $this->assertTrue($evaluation->isTerminal(), "Status {$status} harus terminal.");
        }
    }

    public function test_is_terminal_is_false_for_pending_and_processing(): void
    {
        foreach (['pending', 'processing'] as $status) {
            $evaluation = new AiEvaluation(['status' => $status]);

            $this->assertFalse($evaluation->isTerminal(), "Status {$status} tidak boleh terminal.");
        }

        $this->assertFalse((new AiEvaluation)->isTerminal(), 'Status default tidak boleh terminal.');
    }

    public function test_is_terminal_uses_persisted_status(): void
    {
        $evaluation = AiEvaluation::query()->create($this->evaluationAttributes());
        $this->assertFalse($evaluation->fresh()->isTerminal());

        $evaluation->status = AiEvaluation::STATUS_DONE;
        $evaluation->save();
        $this->assertTrue($evaluation->fresh()->isTerminal());
    }

    public function test_ai_score_and_raw_response_are_hidden(): void
    {
        $evaluation = AiEvaluation::query()->create($this->evaluationAttributes([
            'status' => AiEvaluation::STATUS_DONE,
            'ai_score' => 87.5,
            'raw_response' => '{"verdict":"benar"}',
        ]));

        $hidden = $evaluation->getHidden();
        $this->assertContains('ai_score', $hidden);
        $this->assertContains('raw_response', $hidden);

        $array = $evaluation->fresh()->toArray();
        $this->assertArrayNotHasKey('ai_score', $array);
        $this->assertArrayNotHasKey('raw_response', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('model_drift', $array);

        // Hidden only from serialisation — the values are still readable.
        $this->assertSame('87.50', $evaluation->fresh()->ai_score);
        $this->assertSame('{"verdict":"benar"}', $evaluation->fresh()->raw_response);
    }

    // ---------------------------------------------------------------------
    // Reversibility
    // ---------------------------------------------------------------------

    public function test_migration_is_reversible(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Rollback assertions are MySQL specific.');
        }

        $this->assertTrue(Schema::hasTable('ai_evaluations'), 'up() should create ai_evaluations.');
        $this->assertTrue(Schema::hasTable('ai_evaluation_attempts'), 'up() should create ai_evaluation_attempts.');

        $this->runMigration('down');
        $this->assertFalse(Schema::hasTable('ai_evaluation_attempts'), 'down() should drop ai_evaluation_attempts.');
        $this->assertFalse(Schema::hasTable('ai_evaluations'), 'down() should drop ai_evaluations.');

        $this->runMigration('up');
        $this->assertTrue(Schema::hasTable('ai_evaluations'), 'up() should recreate ai_evaluations.');
        $this->assertTrue(Schema::hasTable('ai_evaluation_attempts'), 'up() should recreate ai_evaluation_attempts.');
        $this->assertTrue(Schema::hasColumn('ai_evaluations', 'model_drift'));
        $this->assertTrue(Schema::hasColumn('ai_evaluation_attempts', 'error_code'));

        // Rebuilt schema must still enforce idempotency and cascade.
        AiEvaluation::query()->create($this->evaluationAttributes());
        $this->captureQueryException(fn () => AiEvaluation::query()->create($this->evaluationAttributes()));
        $this->assertSame(1, AiEvaluation::query()->count());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * A complete, unique idempotency key.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function evaluationAttributes(array $overrides = []): array
    {
        return array_merge([
            'jawaban_id' => 42,
            'jawaban_type' => 'tp',
            'answer_hash' => hash('sha256', 'jawaban asli'),
            'version_set_id' => 7,
            'ai_config_id' => 3,
        ], $overrides);
    }

    private function insertEvaluation(): int
    {
        return DB::table('ai_evaluations')->insertGetId(array_merge($this->evaluationAttributes(), [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function captureQueryException(callable $callback): QueryException
    {
        try {
            $callback();
        } catch (QueryException $exception) {
            return $exception;
        }

        $this->fail('Database harus menolak duplikat kunci idempotensi.');
    }

    private function runMigration(string $direction): void
    {
        // Match the exact file stem so the timestamp is not a prefix of another.
        $path = collect(glob(database_path('migrations/*.php')))
            ->first(fn (string $file): bool => basename($file, '.php') === self::MIGRATION);

        $this->assertNotNull($path, 'Migration '.self::MIGRATION.' not found.');

        $migration = require $path;
        $migration->{$direction}();
    }
}
