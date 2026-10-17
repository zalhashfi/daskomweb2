<?php

namespace Tests\Feature;

use App\Models\AiEvaluation;
use App\Models\AiEvaluationAttempt;
use App\Models\Asisten;
use App\Models\JawabanMandiri;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalMandiri;
use App\Models\SoalTp;
use App\Models\SubmissionUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * POST /api-v1/modul/reset (body `modul_id` / `modul_ids[]`, or the
 * /modul/{id}/reset route) deletes the submission data of ONLY the requested
 * modul(s), child-first inside one DB::transaction.
 *
 * Guarantees under test:
 * - modul A ends with 0 submissions and 0 evaluations;
 * - modul B keeps exactly the same number of rows in every table;
 * - the delete order satisfies the real foreign keys (no FK_CHECK toggle);
 * - a failure halfway through rolls every table back.
 */
class ModulResetSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api-v1/modul/reset';

    private Asisten $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);

        $role = Role::create(['name' => 'TEST-RESET-ROLE', 'guard_name' => 'asisten']);
        $permission = Permission::firstOrCreate(['name' => 'manage-modul', 'guard_name' => 'asisten']);
        $role->syncPermissions([$permission]);

        $this->assistant = Asisten::factory()->create(['role_id' => $role->id]);
        $this->assistant->assignRole($role);
        $this->actingAs($this->assistant, 'asisten');
    }

    public function test_reset_removes_only_the_target_modul_submissions_and_evaluations(): void
    {
        $fixture = $this->seedTwoModuls();

        $before = $this->rowCounts();

        $response = $this->postJson(self::ENDPOINT, ['modul_id' => $fixture['modulA']->id]);

        $response->assertOk()->assertJsonPath('modul_ids', [$fixture['modulA']->id]);

        $this->assertSame(0, JawabanSubmission::query()->where('modul_id', $fixture['modulA']->id)->count());
        $this->assertSame(0, JawabanTp::query()->where('modul_id', $fixture['modulA']->id)->count());
        $this->assertSame(0, JawabanMandiri::query()->where('modul_id', $fixture['modulA']->id)->count());
        $this->assertSame(0, DB::table('ai_evaluations')
            ->whereIn('id', $fixture['modulAEvaluationIds'])->count(), 'Evaluasi modul A harus terhapus.');
        $this->assertSame(0, DB::table('ai_evaluation_attempts')
            ->whereIn('ai_evaluation_id', $fixture['modulAEvaluationIds'])->count());
        $this->assertSame(0, DB::table('submission_unlocks')
            ->whereIn('submission_id', $fixture['modulASubmissionIds'])->count());

        // Modul B is untouched: identical row count in every related table.
        $this->assertSame(
            count($fixture['modulBSubmissionIds']),
            JawabanSubmission::query()->where('modul_id', $fixture['modulB']->id)->count()
        );
        $this->assertSame(
            count($fixture['modulBEvaluationIds']),
            DB::table('ai_evaluations')->whereIn('id', $fixture['modulBEvaluationIds'])->count()
        );
        $this->assertSame(
            2, // modul A has two submissions, each unlocked once
            $before['submission_unlocks'] - DB::table('submission_unlocks')->count()
        );
        $this->assertSame(
            count($fixture['modulBEvaluationIds']),
            DB::table('submission_unlocks')->whereIn('submission_id', $fixture['modulBSubmissionIds'])->count()
        );
        $this->assertSame(2, JawabanTp::query()->where('modul_id', $fixture['modulB']->id)->count());
        $this->assertSame(1, JawabanMandiri::query()->where('modul_id', $fixture['modulB']->id)->count());

        // And the module row itself survives a data reset.
        $this->assertDatabaseHas('moduls', ['id' => $fixture['modulA']->id]);

        // No raw truncate / FK-check toggle is left behind.
        $after = $this->rowCounts();
        $this->assertSame($before['soal_tps'], $after['soal_tps']);
        $this->assertSame($before['soal_mandiris'], $after['soal_mandiris']);
        $this->assertSame($before['praktikans'], $after['praktikans']);

        // Only modul A's rows are gone.
        $this->assertSame($before['jawaban_submissions'] - 2, $after['jawaban_submissions']);
        $this->assertSame($before['ai_evaluations'] - 2, $after['ai_evaluations']);
        $this->assertSame($before['ai_evaluation_attempts'] - 2, $after['ai_evaluation_attempts']);
        $this->assertSame($before['submission_unlocks'] - 2, $after['submission_unlocks']);
        $this->assertSame($before['jawaban_tps'] - 1, $after['jawaban_tps']);
        $this->assertSame($before['jawaban_mandiris'] - 1, $after['jawaban_mandiris']);
    }

    public function test_reset_accepts_a_list_of_modul_ids(): void
    {
        $fixture = $this->seedTwoModuls();

        $response = $this->postJson(self::ENDPOINT, [
            'modul_ids' => [$fixture['modulA']->id, $fixture['modulB']->id],
        ]);

        $response->assertOk();

        $this->assertSame(0, JawabanSubmission::query()->count());
        $this->assertSame(0, JawabanTp::query()->count());
        $this->assertSame(0, JawabanMandiri::query()->count());
        $this->assertSame(0, DB::table('ai_evaluations')->count());
        $this->assertSame(0, DB::table('ai_evaluation_attempts')->count());
        $this->assertSame(0, DB::table('submission_unlocks')->count());
    }

    public function test_reset_route_with_id_parameter_works(): void
    {
        $fixture = $this->seedTwoModuls();

        $response = $this->postJson('/api-v1/modul/'.$fixture['modulA']->id.'/reset');

        $response->assertOk();
        $this->assertSame(0, JawabanSubmission::query()->where('modul_id', $fixture['modulA']->id)->count());
        $this->assertSame(
            count($fixture['modulBSubmissionIds']),
            JawabanSubmission::query()->where('modul_id', $fixture['modulB']->id)->count()
        );
    }

    public function test_reset_without_target_modul_is_rejected(): void
    {
        $fixture = $this->seedTwoModuls();

        $response = $this->postJson(self::ENDPOINT, []);

        $response->assertStatus(422);
        $this->assertSame(
            count($fixture['modulASubmissionIds']) + count($fixture['modulBSubmissionIds']),
            JawabanSubmission::query()->count()
        );
    }

    public function test_failure_midway_rolls_back_every_table(): void
    {
        $fixture = $this->seedTwoModuls();

        $before = $this->rowCounts();

        // Fail on the DELETE against jawaban_submissions: by then the
        // attempts, evaluations and answer rows of modul A have already been
        // removed INSIDE the transaction, which is exactly the "failure
        // halfway through" case the rollback must undo.
        DB::listen(function ($query): void {
            $sql = $query->sql;

            if (str_starts_with($sql, 'delete from')
                && str_contains($sql, '`jawaban_submissions`')) {
                throw new RuntimeException('Simulated failure halfway through reset.');
            }
        });

        $response = $this->postJson(self::ENDPOINT, ['modul_id' => $fixture['modulA']->id]);

        $response->assertStatus(500);

        $this->assertSame(
            $before,
            $this->rowCounts(),
            'Kegagalan di tengah harus me-rollback seluruh tabel.'
        );
    }

    public function test_reset_does_not_toggle_foreign_key_checks(): void
    {
        $fixture = $this->seedTwoModuls();

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->postJson(self::ENDPOINT, ['modul_id' => $fixture['modulA']->id])->assertOk();

        foreach ($statements as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('FOREIGN_KEY_CHECKS', $sql);
            $this->assertStringNotContainsStringIgnoringCase('truncate', $sql);
        }
    }

    /**
     * Seed modul A and modul B, each with tp + tm submissions, evaluations,
     * attempts and unlock rows.
     *
     * @return array<string, mixed>
     */
    private function seedTwoModuls(): array
    {
        $modulA = Modul::factory()->create();
        $modulB = Modul::factory()->create();

        $praktikan = Praktikan::factory()->create();
        $other = Praktikan::factory()->create();

        $soalATp = SoalTp::factory()->for($modulA)->create();
        $soalATm = SoalMandiri::factory()->for($modulA)->create();
        $soalBTp = SoalTp::factory()->for($modulB)->create();
        $soalBTp2 = SoalTp::factory()->for($modulB)->create();
        $soalBTm = SoalMandiri::factory()->for($modulB)->create();

        $modulASubmissionIds = [];
        $modulAEvaluationIds = [];
        $modulBSubmissionIds = [];
        $modulBEvaluationIds = [];

        $seeder = function (
            Modul $modul,
            Praktikan $owner,
            string $tipe,
            array $soalIds,
            array &$submissionIds,
            array &$evaluationIds
        ): void {
            $submission = JawabanSubmission::create([
                'praktikan_id' => $owner->id,
                'modul_id' => $modul->id,
                'tipe' => $tipe,
                'attempt_no' => 1,
            ]);
            $submissionIds[] = $submission->id;

            foreach ($soalIds as $soalId) {
                $answerId = $tipe === JawabanSubmission::TIPE_TP
                    ? JawabanTp::create([
                        'submission_id' => $submission->id,
                        'praktikan_id' => $owner->id,
                        'modul_id' => $modul->id,
                        'soal_id' => $soalId,
                        'jawaban' => 'Jawaban modul '.$modul->id.'.',
                    ])->id
                    : JawabanMandiri::create([
                        'submission_id' => $submission->id,
                        'praktikan_id' => $owner->id,
                        'modul_id' => $modul->id,
                        'soal_id' => $soalId,
                        'jawaban' => 'Jawaban modul '.$modul->id.'.',
                    ])->id;

                $evaluation = AiEvaluation::create([
                    'jawaban_id' => $answerId,
                    'jawaban_type' => $tipe,
                    'answer_hash' => hash('sha256', $tipe.$answerId),
                    'status' => AiEvaluation::STATUS_DONE,
                ]);
                $evaluationIds[] = $evaluation->id;

                AiEvaluationAttempt::create([
                    'ai_evaluation_id' => $evaluation->id,
                    'attempt_no' => 1,
                    'http_status' => 200,
                    'outcome' => 'ok',
                ]);

                SubmissionUnlock::create([
                    'submission_id' => $submission->id,
                    'unlocked_by_asisten_id' => $this->assistant->id,
                    'reason' => 'Dibuka ulang untuk pengujian reset modul.',
                ]);
            }
        };

        $seeder($modulA, $praktikan, JawabanSubmission::TIPE_TP, [$soalATp->id], $modulASubmissionIds, $modulAEvaluationIds);
        $seeder($modulA, $praktikan, JawabanSubmission::TIPE_TM, [$soalATm->id], $modulASubmissionIds, $modulAEvaluationIds);
        $seeder($modulB, $other, JawabanSubmission::TIPE_TP, [$soalBTp->id, $soalBTp2->id], $modulBSubmissionIds, $modulBEvaluationIds);
        $seeder($modulB, $other, JawabanSubmission::TIPE_TM, [$soalBTm->id], $modulBSubmissionIds, $modulBEvaluationIds);

        return compact(
            'modulA',
            'modulB',
            'praktikan',
            'other',
            'modulASubmissionIds',
            'modulAEvaluationIds',
            'modulBSubmissionIds',
            'modulBEvaluationIds',
        );
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];

        foreach ([
            'ai_evaluation_attempts',
            'ai_evaluations',
            'jawaban_tps',
            'jawaban_mandiris',
            'jawaban_submissions',
            'submission_unlocks',
            'soal_tps',
            'soal_mandiris',
            'moduls',
            'praktikans',
        ] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
