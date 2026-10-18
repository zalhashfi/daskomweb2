<?php

namespace Tests\Feature;

use App\Jobs\EvaluateAnswer;
use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\AiEvaluationAttempt;
use App\Models\Asisten;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use App\Services\Ai\AiClientManager;
use App\Services\Ai\AiGate;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * T23 — GET /api-v1/jawaban-tp/{nim}/{modulId} goes through
 * App\Http\Resources\JawabanVerdictResource (SPEC §7.2).
 *
 * End-to-end: Queue::fake() → submit two answers over the real
 * SubmissionWriter (which runs the T23a chokepoint and creates the queued
 * ai_evaluations rows) → EvaluateAnswer::handle() on the rows that exist →
 * GET the endpoint as an assistant.
 *
 * Contract checked here:
 *  - field order: success → data.provider_status → data.submission →
 *    data.jawabanData[];
 *  - every answer has ai = {status, verdict, alasan, max_score} and nothing
 *    else — no ai_score, no raw_response, no cited_chunk_ids;
 *  - the standard answer (kunci) never appears in the response;
 *  - a submission without answers → 200, ai.status = no_submission.
 */
class JawabanVerdictResponseTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://gateway.test';

    private const CHAT_MODEL = 'google/gemini-flash';

    private const SECRET_KEY = 'RAHASIA_KUNCI_RESISTOR_HAMBATAN_TETAP';

    private const AI_KEYS = ['status', 'verdict', 'alasan', 'max_score'];

    /**
     * The SOFTWARE/DDC-style approver recorded on the kunci (dual-control:
     * never the author).
     */
    private Asisten $approver;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->approver = $this->makeAsisten('verdict-approver');

        config([
            'ai.openai_compatible.base_url' => self::BASE_URL,
            'ai.openai_compatible.api_key' => 'sk-test-secret-verdict',
            'ai.openai_compatible.chat_model' => self::CHAT_MODEL,
            'ai.openai_compatible.embed_model' => 'google/gemini-embedding-001',
        ]);
    }

    // ---------------------------------------------------------------------
    // Happy path
    // ---------------------------------------------------------------------

    public function test_done_evaluation_is_shown_as_badge_without_internal_numbers(): void
    {
        Queue::fake();
        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion($this->verdictJson('sesuai', 95)),
                200,
            ),
        ]);

        $modul = $this->modulWithApprovedKey();
        $praktikan = Praktikan::factory()->create();
        [$soalA, $soalB] = $this->twoSoalWithKunci($modul);

        $submission = $this->submitTwoAnswers($praktikan, $modul, $soalA, $soalB);
        $this->runJobsFor($submission);

        $this->actingAsAssistant();

        $response = $this->getJson($this->endpoint($praktikan, $modul));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.submission.id', $submission->id)
            ->assertJsonPath('data.submission.attempt_no', 1)
            ->assertJsonPath('data.jawabanData.0.soal_id', $soalA->id)
            ->assertJsonPath('data.jawabanData.0.ai.status', 'done')
            ->assertJsonPath('data.jawabanData.0.ai.verdict', 'sesuai')
            ->assertJsonPath('data.jawabanData.0.ai.max_score', 100)
            ->assertJsonPath('data.jawabanData.1.ai.status', 'done')
            ->assertJsonPath('data.jawabanData.1.ai.verdict', 'sesuai')
            ->assertJsonMissingPath('data.jawabanData.0.ai.ai_score')
            ->assertJsonMissingPath('data.jawabanData.0.ai.raw_response')
            ->assertJsonMissingPath('data.jawabanData.0.ai.cited_chunk_ids')
            ->assertJsonMissingPath('data.jawabanData.1.ai.ai_score');

        $payload = $response->json('data.jawabanData');

        $this->assertCount(2, $payload);

        foreach ($payload as $index => $row) {
            $this->assertSame(
                self::AI_KEYS,
                array_keys($row['ai']),
                "data.jawabanData.{$index}.ai harus hanya memuat status, verdict, alasan, max_score."
            );
            $this->assertIsString($row['ai']['alasan']);
            $this->assertNotSame('', trim($row['ai']['alasan']));
        }

        $this->assertSame(AiEvaluation::STATUS_DONE, AiEvaluation::query()->first()->status);
    }

    public function test_standard_answer_text_never_leaves_the_server(): void
    {
        Queue::fake();
        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion($this->verdictJson('sesuai', 90)),
                200,
            ),
        ]);

        $modul = $this->modulWithApprovedKey();
        $praktikan = Praktikan::factory()->create();
        [$soalA, $soalB] = $this->twoSoalWithKunci($modul);

        $submission = $this->submitTwoAnswers($praktikan, $modul, $soalA, $soalB);
        $this->runJobsFor($submission);

        $this->actingAsAssistant();

        $response = $this->getJson($this->endpoint($praktikan, $modul));

        $response->assertOk()
            ->assertDontSee(self::SECRET_KEY, false)
            ->assertDontSee('raw_response', false)
            ->assertDontSee('cited_chunk_ids', false)
            ->assertDontSee('"ai_score"', false);

        // Guard against the test being vacuous: the key is really stored.
        $this->assertSame(
            2,
            SoalVersionItem::query()->where('jawaban_standar', 'like', '%'.self::SECRET_KEY.'%')->count(),
        );
    }

    // ---------------------------------------------------------------------
    // No submission
    // ---------------------------------------------------------------------

    public function test_submission_without_answers_returns_no_submission(): void
    {
        $modul = $this->modulWithApprovedKey();
        $praktikan = Praktikan::factory()->create();
        $this->twoSoalWithKunci($modul);

        // A submission row exists, but it carries no answer rows at all.
        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TP,
            'attempt_no' => 1,
        ]);

        $this->actingAsAssistant();

        $response = $this->getJson($this->endpoint($praktikan, $modul));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.submission.id', $submission->id)
            ->assertJsonPath('data.jawabanData.0.ai.status', 'no_submission')
            ->assertJsonPath('data.jawabanData.0.ai.verdict', null)
            ->assertJsonPath('data.jawabanData.1.ai.status', 'no_submission')
            ->assertJsonPath('data.jawabanData.0.jawaban', '-');
    }

    public function test_praktikan_without_submission_returns_no_submission(): void
    {
        $modul = $this->modulWithApprovedKey();
        $praktikan = Praktikan::factory()->create();
        $this->twoSoalWithKunci($modul);

        $this->actingAsAssistant();

        $this->getJson($this->endpoint($praktikan, $modul))
            ->assertOk()
            ->assertJsonPath('data.submission', null)
            ->assertJsonPath('data.jawabanData.0.ai.status', 'no_submission')
            ->assertJsonPath('data.jawabanData.1.ai.status', 'no_submission');
    }

    // ---------------------------------------------------------------------
    // Statuses that are not "done"
    // ---------------------------------------------------------------------

    public function test_unapproved_key_is_key_pending_without_verdict(): void
    {
        Queue::fake();
        Http::fake();

        $modul = $this->modulWithApprovedKey();
        $praktikan = Praktikan::factory()->create();
        [$soalA, $soalB] = $this->twoSoalWithKunci($modul);

        // forceFill skips the approval observer so both kunci stay pending.
        SoalVersionItem::query()->update([
            'jawaban_standar_status' => SoalVersionItem::STATUS_PENDING,
        ]);

        $submission = $this->submitTwoAnswers($praktikan, $modul, $soalA, $soalB);
        $this->runJobsFor($submission);

        Http::assertNothingSent();

        $this->actingAsAssistant();

        $this->getJson($this->endpoint($praktikan, $modul))
            ->assertOk()
            ->assertJsonPath('data.jawabanData.0.ai.status', 'key_pending')
            ->assertJsonPath('data.jawabanData.0.ai.verdict', null)
            ->assertJsonMissingPath('data.jawabanData.0.ai.ai_score');
    }

    public function test_answer_without_evaluation_row_is_no_submission(): void
    {
        $modul = $this->modulWithApprovedKey();
        $praktikan = Praktikan::factory()->create();
        [$soalA, $soalB] = $this->twoSoalWithKunci($modul);

        // Submit first while AI is off: the chokepoint creates no row, the
        // answers do exist (SPEC §7.1: ai_enabled=false → 0 queued).
        AiConfig::active()->forceFill(['ai_enabled' => false])->save();

        Queue::fake();
        $submission = $this->submitTwoAnswers($praktikan, $modul, $soalA, $soalB);

        Queue::assertNothingPushed();
        $this->assertSame(0, AiEvaluation::query()->count());
        $this->assertSame(2, JawabanTp::query()->where('submission_id', $submission->id)->count());

        $this->actingAsAssistant();

        $this->getJson($this->endpoint($praktikan, $modul))
            ->assertOk()
            ->assertJsonPath('data.submission.id', $submission->id)
            ->assertJsonPath('data.jawabanData.0.ai.status', 'no_submission')
            ->assertJsonPath('data.jawabanData.0.ai.verdict', null)
            ->assertJsonPath('data.jawabanData.1.ai.status', 'no_submission');
    }

    public function test_queued_row_is_shown_until_the_job_runs(): void
    {
        $modul = $this->modulWithApprovedKey();
        $praktikan = Praktikan::factory()->create();
        [$soalA, $soalB] = $this->twoSoalWithKunci($modul);

        Queue::fake();
        $this->submitTwoAnswers($praktikan, $modul, $soalA, $soalB);

        $this->assertSame(2, AiEvaluation::query()->where('status', AiEvaluation::STATUS_QUEUED)->count());

        $this->actingAsAssistant();

        $this->getJson($this->endpoint($praktikan, $modul))
            ->assertOk()
            ->assertJsonPath('data.jawabanData.0.ai.status', 'queued')
            ->assertJsonPath('data.jawabanData.0.ai.verdict', null)
            ->assertJsonMissingPath('data.jawabanData.0.ai.ai_score');
    }

    // ---------------------------------------------------------------------
    // Endpoint selection
    // ---------------------------------------------------------------------

    public function test_only_latest_attempt_answers_are_returned(): void
    {
        $modul = $this->modulWithApprovedKey();
        $praktikan = Praktikan::factory()->create();
        [$soalA, $soalB] = $this->twoSoalWithKunci($modul);

        $this->submitTwoAnswers($praktikan, $modul, $soalA, $soalB);
        $latest = $this->submitTwoAnswers($praktikan, $modul, $soalA, $soalB, 'Jawaban percobaan kedua.');

        $this->actingAsAssistant();

        $this->getJson($this->endpoint($praktikan, $modul))
            ->assertOk()
            ->assertJsonPath('data.submission.id', $latest->id)
            ->assertJsonPath('data.submission.attempt_no', 2)
            ->assertJsonPath('data.jawabanData.0.jawaban', 'Jawaban percobaan kedua.');
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    private function modulWithApprovedKey(): Modul
    {
        $modul = Modul::factory()->create();

        AiConfig::query()->create([
            'driver' => 'openai_compatible',
            'base_url' => self::BASE_URL,
            'chat_model' => self::CHAT_MODEL,
            'retrieval_top_k' => 0, // Endpoint tests; RAG retrieval is covered by EvaluateAnswerRagTest.
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'allow_external_payload' => true,
            'ai_enabled' => true,
            'budget_tokens_per_modul' => 500000,
            'provider_policy_verified_at' => now(),
            'is_active' => true,
        ]);

        SoalVersionSet::create([
            'modul_id' => $modul->id,
            'version_no' => 1,
            'status' => SoalVersionSet::STATUS_APPROVED,
            'bobot_total' => 100,
            'activated_at' => now(),
        ]);

        return $modul;
    }

    /**
     * Two TP questions of the modul, each with an approved standard answer
     * (one version item per soal, bobot 50 each).
     *
     * @return array{0: SoalTp, 1: SoalTp}
     */
    private function twoSoalWithKunci(Modul $modul): array
    {
        $versionSet = SoalVersionSet::query()->where('modul_id', $modul->id)->sole();

        $soalA = SoalTp::factory()->create([
            'modul_id' => $modul->id,
            'soal' => 'Apa fungsi resistor?',
        ]);

        $soalB = SoalTp::factory()->create([
            'modul_id' => $modul->id,
            'soal' => 'Sebutkan hukum Ohm.',
        ]);

        foreach ([[$soalA, 'Resistor menghambat arus listrik ('.self::SECRET_KEY.')'], [$soalB, 'V = I x R ('.self::SECRET_KEY.')']] as [$soal, $kunci]) {
            SoalVersionItem::create([
                'version_set_id' => $versionSet->id,
                'soal_type' => SoalVersionItem::SOAL_TP,
                'soal_id' => $soal->id,
                'teks_snapshot' => $soal->soal,
                'bobot' => 50,
                'max_score' => 100,
                'jawaban_standar' => $kunci,
                'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
                'jawaban_standar_approved_by' => $this->approver->id,
                'jawaban_standar_approved_at' => now(),
            ]);
        }

        return [$soalA, $soalB];
    }

    private function submitTwoAnswers(
        Praktikan $praktikan,
        Modul $modul,
        SoalTp $soalA,
        SoalTp $soalB,
        string $soalAAnswer = 'Resistor membatasi arus listrik.',
    ): JawabanSubmission {
        $permission = Permission::firstOrCreate([
            'name' => 'praktikum-lms',
            'guard_name' => 'praktikan',
        ]);

        $role = $this->roleNamed('verdict-praktikan', 'praktikan');
        $role->givePermissionTo($permission);
        $praktikan->assignRole($role);

        $this->actingAs($praktikan, 'praktikan');

        $response = $this->postJson('/api-v1/jawaban-tp', [
            [
                'praktikan_id' => $praktikan->id,
                'modul_id' => $modul->id,
                'soal_id' => $soalA->id,
                'jawaban' => $soalAAnswer,
            ],
            [
                'praktikan_id' => $praktikan->id,
                'modul_id' => $modul->id,
                'soal_id' => $soalB->id,
                'jawaban' => 'Tegangan sama dengan arus kali hambatan.',
            ],
        ]);

        $response->assertCreated();

        \Illuminate\Support\Facades\Auth::guard('praktikan')->logout();

        return JawabanSubmission::query()->findOrFail($response->json('submission_id'));
    }

    /**
     * Run EvaluateAnswer::handle() for every queued row of the submission —
     * the queue itself is faked, so the T27 dispatch is only recorded.
     */
    private function runJobsFor(JawabanSubmission $submission): void
    {
        // T27 dispatches one job per queued row after commit; this helper runs
        // the rows itself instead of the queue.
        Queue::assertPushed(EvaluateAnswer::class, 2);

        $rows = AiEvaluation::query()
            ->where('jawaban_type', JawabanSubmission::TIPE_TP)
            ->whereIn(
                'jawaban_id',
                JawabanTp::query()->where('submission_id', $submission->id)->pluck('id')->all(),
            )
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows, 'Chokepoint harus membuat dua baris ai_evaluations queued.');

        foreach ($rows as $row) {
            (new EvaluateAnswer($row))->handle(
                app(AiClientManager::class),
                app(AiGate::class),
                app(PromptBuilder::class),
            );
        }

        $this->assertSame(0, AiEvaluationAttempt::query()->where('outcome', 'failed')->count());
    }

    private function actingAsAssistant(): Asisten
    {
        $role = $this->roleNamed('assistant-verdict', 'asisten');
        $role->givePermissionTo(Permission::firstOrCreate([
            'name' => 'nilai-praktikan',
            'guard_name' => 'asisten',
        ]));

        $assistant = Asisten::factory()->create(['role_id' => $role->id]);
        $assistant->assignRole($role);

        $this->actingAs($assistant, 'asisten');

        return $assistant;
    }

    /**
     * A role that really exists before the assistant row references it.
     *
     * The Asisten factory assigns a RANDOM role_id (1-6); depending on which
     * roles happen to exist the FK may reject that row, so every assistant in
     * this test is tied to an explicit role instead.
     */
    private function roleNamed(string $name, string $guard): Role
    {
        $role = Role::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role;
    }

    private function makeAsisten(string $roleName): Asisten
    {
        $role = $this->roleNamed($roleName, 'asisten');

        return Asisten::factory()->create(['role_id' => $role->id]);
    }

    private function endpoint(Praktikan $praktikan, Modul $modul): string
    {
        return "/api-v1/jawaban-tp/{$praktikan->nim}/{$modul->id}";
    }

    private function verdictJson(string $verdict, int|float $score): string
    {
        return (string) json_encode([
            'verdict' => $verdict,
            'score' => $score,
            'alasan' => 'Jawaban sesuai dengan kunci jawaban.',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    private function chatCompletion(string $content): array
    {
        return [
            'id' => 'chatcmpl-verdict-1',
            'object' => 'chat.completion',
            'model' => self::CHAT_MODEL,
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 320, 'completion_tokens' => 24],
        ];
    }
}
