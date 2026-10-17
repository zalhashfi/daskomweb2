<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use App\Services\Ai\EvaluationChokepoint;
use App\Services\Jawaban\SubmissionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T23a — a submission is bound to the modul's ACTIVE version set and the
 * chokepoint EvaluationChokepoint is the only creator of ai_evaluations rows.
 *
 * - active set v2            => submission.version_set_id = v2, 1 `queued` row
 *                               per text answer
 * - attachment answer        => 1 `manual_required` row, created here
 * - no active set            => version_set_id null, 0 rows
 * - payload policy false/null=> 0 rows, 0 dispatch
 * - second chokepoint call   => no extra rows (unique key)
 */
class SubmissionVersionBindingTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api-v1/jawaban-tp';

    private const MIGRATION = '2026_10_17_250000_add_version_set_id_to_jawaban_submissions_table';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        config([
            'ai.openai_compatible.base_url' => 'http://127.0.0.1:20128',
            'ai.openai_compatible.api_key' => 'sk-test-secret-binding',
            'ai.openai_compatible.chat_model' => 'gemini/gemini-flash',
        ]);
    }

    // ---------------------------------------------------------------------
    // Schema
    // ---------------------------------------------------------------------

    public function test_submission_has_version_set_column_with_null_on_delete_foreign_key(): void
    {
        $this->assertTrue(Schema::hasColumn('jawaban_submissions', 'version_set_id'));

        $foreign = collect(Schema::getForeignKeys('jawaban_submissions'))
            ->firstWhere('columns', ['version_set_id']);

        $this->assertNotNull($foreign, 'version_set_id harus punya foreign key.');
        $this->assertSame('soal_version_sets', $foreign['foreign_table']);
        $this->assertSame(['id'], $foreign['foreign_columns']);
    }

    public function test_deleting_version_set_nulls_the_binding_instead_of_the_submission(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $v2 = $this->versionSet($modul, 2, now()->utc());

        $submission = $this->writer()->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
            ['soal_id' => SoalTp::factory()->for($modul)->create()->id, 'jawaban' => 'Isi.'],
        ]);

        $this->assertSame($v2->id, $submission->fresh()->version_set_id);

        // Items cascade, but the set itself is referenced by the submission.
        DB::table('soal_version_items')->where('version_set_id', $v2->id)->delete();
        DB::table('soal_version_sets')->where('id', $v2->id)->delete();

        $this->assertNull($submission->fresh()->version_set_id);
    }

    // ---------------------------------------------------------------------
    // Binding
    // ---------------------------------------------------------------------

    public function test_submit_binds_newest_active_version_set_and_queues_one_row_per_text_answer(): void
    {
        Queue::fake();

        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $this->versionSet($modul, 1, now()->utc()->subDay());
        $v2 = $this->versionSet($modul, 2, now()->utc()->subHour());
        $draft = $this->versionSet($modul, 3);

        $config = $this->allowPayloadConfig('openai_compatible');

        $soalA = SoalTp::factory()->for($modul)->create();
        $soalB = SoalTp::factory()->for($modul)->create();

        $response = $this->postJson(self::ENDPOINT, [
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soalA->id,
            'jawaban' => 'Jawaban A.',
        ]);
        $response->assertCreated();

        $submission = JawabanSubmission::query()->findOrFail($response->json('submission_id'));

        $this->assertSame($v2->id, $submission->version_set_id, 'Set aktif terbaru (v2) harus dipakai, bukan v1/draft.');
        $this->assertNull($draft->activated_at);

        $rows = AiEvaluation::query()
            ->where('version_set_id', $v2->id)
            ->where('ai_config_id', $config->id)
            ->get();

        $this->assertCount(1, $rows);
        $row = $rows->sole();
        $this->assertSame('queued', $row->status);
        $this->assertSame('tp', $row->jawaban_type);
        $this->assertSame('openai_compatible', $row->driver);
        $this->assertSame(
            hash('sha256', 'Jawaban A.'),
            $row->answer_hash,
            'answer_hash harus sha256 teks jawaban.'
        );
        $this->assertSame(
            JawabanTp::query()->where('submission_id', $submission->id)->where('soal_id', $soalA->id)->value('id'),
            $row->jawaban_id
        );

        // A second submit with two answers → two more rows, one per answer.
        $response = $this->postJson(self::ENDPOINT, [
            ['praktikan_id' => $praktikan->id, 'modul_id' => $modul->id, 'soal_id' => $soalA->id, 'jawaban' => 'Jawaban A v2.'],
            ['praktikan_id' => $praktikan->id, 'modul_id' => $modul->id, 'soal_id' => $soalB->id, 'jawaban' => 'Jawaban B.'],
        ]);
        $response->assertCreated()->assertJsonPath('attempt_no', 2);

        $second = JawabanSubmission::query()->findOrFail($response->json('submission_id'));
        $this->assertSame($v2->id, $second->version_set_id);
        $this->assertSame(2, AiEvaluation::query()->whereIn('jawaban_id', $this->answerIdsOf($second))->count());
        $this->assertSame(3, AiEvaluation::query()->count());

        Queue::assertNothingPushed();
    }

    public function test_answer_hash_uses_answer_text_truncated_to_max_answer_chars(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $this->versionSet($modul, 1, now()->utc());
        $soal = SoalTp::factory()->for($modul)->create();

        $this->allowPayloadConfig('openai_compatible', ['max_answer_chars' => 10]);

        $submission = $this->writer()->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
            ['soal_id' => $soal->id, 'jawaban' => str_repeat('a', 25)],
        ]);

        $row = AiEvaluation::query()->where('version_set_id', $submission->version_set_id)->sole();

        $this->assertSame(hash('sha256', str_repeat('a', 10)), $row->answer_hash);
    }

    // ---------------------------------------------------------------------
    // Lampiran
    // ---------------------------------------------------------------------

    public function test_attachment_answer_creates_exactly_one_manual_required_row(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $v2 = $this->versionSet($modul, 2, now()->utc());

        $lampiran = SoalTp::factory()->for($modul)->create(['enable_file_upload' => true]);
        $teks = SoalTp::factory()->for($modul)->create(['enable_file_upload' => false]);

        $this->allowPayloadConfig('openai_compatible');

        $submission = $this->writer()->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
            ['soal_id' => $lampiran->id, 'jawaban' => '-'],
            ['soal_id' => $teks->id, 'jawaban' => 'Jawaban teks.'],
        ]);

        $attachmentId = JawabanTp::query()
            ->where('submission_id', $submission->id)
            ->where('soal_id', $lampiran->id)
            ->sole()
            ->id;

        $manual = AiEvaluation::query()->where('jawaban_id', $attachmentId)->get();
        $this->assertCount(1, $manual, 'Jawaban lampiran harus punya tepat satu baris evaluasi.');
        $this->assertSame('manual_required', $manual->sole()->status);
        $this->assertSame($v2->id, $manual->sole()->version_set_id);

        $textId = JawabanTp::query()
            ->where('submission_id', $submission->id)
            ->where('soal_id', $teks->id)
            ->sole()
            ->id;

        $this->assertSame(
            'queued',
            AiEvaluation::query()->where('jawaban_id', $textId)->sole()->status,
            'Jawaban teks harus queued, bukan manual_required.'
        );

        $this->assertSame(2, AiEvaluation::query()->count());
    }

    // ---------------------------------------------------------------------
    // Tanpa set aktif
    // ---------------------------------------------------------------------

    public function test_submit_without_active_version_set_binds_null_and_creates_no_evaluation(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $draft = $this->versionSet($modul, 1); // drafted, never activated
        $this->assertNull($draft->fresh()->activated_at);
        $soal = SoalTp::factory()->for($modul)->create();

        $this->allowPayloadConfig('openai_compatible');

        $response = $this->postJson(self::ENDPOINT, [
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'soal_id' => $soal->id,
            'jawaban' => 'Jawaban.',
        ]);
        $response->assertCreated();

        $submission = JawabanSubmission::query()->findOrFail($response->json('submission_id'));

        $this->assertNull($submission->version_set_id);
        $this->assertSame(0, AiEvaluation::query()->count());

        // Chokepoint called directly on such a submission also writes nothing.
        $answerIds = $this->answerIdsOf($submission);
        $this->assertSame(0, app(EvaluationChokepoint::class)->createQueuedEvaluations($submission, $answerIds));
        $this->assertSame(0, AiEvaluation::query()->count());
    }

    // ---------------------------------------------------------------------
    // Gate
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string|null|bool, 1: string|null}>
     */
    public static function blockedPayloadProvider(): array
    {
        return [
            'allow_external_payload null + external driver' => [null, 'openai_compatible'],
            'allow_external_payload false + external driver' => [false, 'openai_compatible'],
            'allow_external_payload true + external driver but ai disabled' => [true, 'openai_compatible'],
        ];
    }

    #[DataProvider('blockedPayloadProvider')]
    public function test_blocked_gate_creates_zero_rows_and_zero_dispatch(?bool $allow, string $driver): void
    {
        Queue::fake();

        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $this->versionSet($modul, 2, now()->utc());
        $soal = SoalTp::factory()->for($modul)->create();

        // The gate must reject for one of these reasons: payload not allowed
        // (false, or a legacy NULL row), or AI switched off entirely.
        $this->allowPayloadConfig($driver, [
            'allow_external_payload' => $allow,
            'ai_enabled' => $allow === true ? false : true,
        ], legacyNullColumn: $allow === null ? 'allow_external_payload' : null);

        $this->writer()->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
            ['soal_id' => $soal->id, 'jawaban' => 'Jawaban.'],
        ]);

        $this->assertSame(0, AiEvaluation::query()->count(), 'Gate menolak → 0 baris ai_evaluations.');
        Queue::assertNothingPushed();
    }

    public function test_null_payload_policy_keeps_zero_rows_even_with_chokepoint_called_twice(): void
    {
        Queue::fake();

        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $this->versionSet($modul, 2, now()->utc());
        $soal = SoalTp::factory()->for($modul)->create();

        // A raw row without the column value (legacy / manual insert).
        $this->allowPayloadConfig('openai_compatible', [], legacyNullColumn: 'allow_external_payload');

        $submission = $this->writer()->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
            ['soal_id' => $soal->id, 'jawaban' => 'Jawaban.'],
        ]);

        $answerIds = $this->answerIdsOf($submission);
        $this->assertSame(0, app(EvaluationChokepoint::class)->createQueuedEvaluations($submission, $answerIds));
        $this->assertSame(0, AiEvaluation::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_unverified_provider_policy_creates_zero_rows(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $this->versionSet($modul, 2, now()->utc());
        $soal = SoalTp::factory()->for($modul)->create();

        $config = $this->allowPayloadConfig('openai_compatible');
        DB::table('ai_configs')->where('id', $config->id)->update(['provider_policy_verified_at' => null]);

        $this->writer()->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
            ['soal_id' => $soal->id, 'jawaban' => 'Jawaban.'],
        ]);

        $this->assertSame(0, AiEvaluation::query()->count());
    }

    // ---------------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------------

    public function test_calling_chokepoint_twice_does_not_add_rows(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $this->versionSet($modul, 2, now()->utc());
        $soalA = SoalTp::factory()->for($modul)->create();
        $soalB = SoalTp::factory()->for($modul)->create();

        $this->allowPayloadConfig('openai_compatible');

        $submission = $this->writer()->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
            ['soal_id' => $soalA->id, 'jawaban' => 'A.'],
            ['soal_id' => $soalB->id, 'jawaban' => 'B.'],
        ]);

        $answerIds = $this->answerIdsOf($submission);
        $this->assertSame(2, AiEvaluation::query()->count());

        $chokepoint = app(EvaluationChokepoint::class);

        $this->assertSame(0, $chokepoint->createQueuedEvaluations($submission, $answerIds));
        $this->assertSame(0, $chokepoint->createQueuedEvaluations($submission, $answerIds));

        $this->assertSame(2, AiEvaluation::query()->count(), 'Panggilan ulang tidak boleh menambah baris.');
        $this->assertSame(1, AiEvaluation::query()->where('jawaban_id', $answerIds[0])->count());
    }

    public function test_chokepoint_returns_number_of_created_rows(): void
    {
        $praktikan = $this->actingAsPraktikan();
        $modul = Modul::factory()->create();
        $this->versionSet($modul, 1, now()->utc());
        $soalA = SoalTp::factory()->for($modul)->create();
        $soalB = SoalTp::factory()->for($modul)->create();

        $this->allowPayloadConfig('openai_compatible');

        $submission = $this->writer()->write(JawabanSubmission::TIPE_TP, $praktikan->id, $modul->id, [
            ['soal_id' => $soalA->id, 'jawaban' => 'A.'],
            ['soal_id' => $soalB->id, 'jawaban' => 'B.'],
        ]);

        $answerIds = $this->answerIdsOf($submission);

        // Rows already exist from submit: chokepoint reports 0 new rows.
        $this->assertSame(0, app(EvaluationChokepoint::class)->createQueuedEvaluations($submission, $answerIds));

        AiEvaluation::query()->delete();
        $this->assertSame(2, app(EvaluationChokepoint::class)->createQueuedEvaluations($submission, $answerIds));
    }

    // ---------------------------------------------------------------------
    // Reversibility
    // ---------------------------------------------------------------------

    public function test_migration_is_reversible(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Rollback assertions are MySQL specific.');
        }

        $this->assertTrue(Schema::hasColumn('jawaban_submissions', 'version_set_id'));

        $this->runMigration('down');
        $this->assertFalse(Schema::hasColumn('jawaban_submissions', 'version_set_id'));

        $this->runMigration('up');
        $this->assertTrue(Schema::hasColumn('jawaban_submissions', 'version_set_id'));

        $foreign = collect(Schema::getForeignKeys('jawaban_submissions'))
            ->firstWhere('columns', ['version_set_id']);
        $this->assertNotNull($foreign, 'FK harus dibuat ulang oleh up().');
        $this->assertSame('soal_version_sets', $foreign['foreign_table']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function writer(): SubmissionWriter
    {
        return app(SubmissionWriter::class);
    }

    private function actingAsPraktikan(): Praktikan
    {
        $praktikan = Praktikan::factory()->create();

        $this->actingAs($praktikan, 'praktikan');

        return $praktikan;
    }

    private function versionSet(Modul $modul, int $versionNo, mixed $activatedAt = null): SoalVersionSet
    {
        $set = SoalVersionSet::query()->create([
            'modul_id' => $modul->id,
            'version_no' => $versionNo,
            'status' => $activatedAt === null ? SoalVersionSet::STATUS_DRAFT : SoalVersionSet::STATUS_APPROVED,
            'bobot_total' => 100,
            'activated_at' => $activatedAt,
        ]);

        foreach (SoalTp::query()->where('modul_id', $modul->id)->get() as $soal) {
            SoalVersionItem::query()->create([
                'version_set_id' => $set->id,
                'soal_type' => SoalVersionItem::SOAL_TP,
                'soal_id' => $soal->id,
                'bobot' => 100,
                'max_score' => 100,
                'teks_snapshot' => $soal->soal,
                'jawaban_standar_status' => SoalVersionItem::STATUS_PENDING,
            ]);
        }

        return $set;
    }

    /**
     * The single active config for the test.
     *
     * Model-created when the column values are settable through the model;
     * otherwise (e.g. a legacy NULL `allow_external_payload`) the row is
     * written raw with that column omitted, so the schema default applies —
     * the same state as a row inserted before the column existed.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function allowPayloadConfig(string $driver, array $overrides = [], ?string $legacyNullColumn = null): AiConfig
    {
        DB::table('ai_configs')->update(['is_active' => false]);

        $attributes = array_merge([
            'driver' => $driver,
            'retrieval_top_k' => 0,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'allow_external_payload' => true,
            'ai_enabled' => true,
            'budget_tokens_per_modul' => 100000,
            'provider_policy_verified_at' => now(),
            'is_active' => true,
        ], $overrides);

        if ($legacyNullColumn === null) {
            return AiConfig::query()->create($attributes);
        }

        unset($attributes[$legacyNullColumn]);

        $id = DB::table('ai_configs')->insertGetId(array_merge($attributes, [
            'provider_policy_verified_at' => now()->utc()->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return AiConfig::query()->findOrFail($id);
    }

    /**
     * @return list<int>
     */
    private function answerIdsOf(JawabanSubmission $submission): array
    {
        return JawabanTp::query()
            ->where('submission_id', $submission->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function runMigration(string $direction): void
    {
        $path = collect(glob(database_path('migrations/*.php')))
            ->first(fn (string $file): bool => basename($file, '.php') === self::MIGRATION);

        $this->assertNotNull($path, 'Migration '.self::MIGRATION.' not found.');

        $migration = require $path;
        $migration->{$direction}();
    }
}
