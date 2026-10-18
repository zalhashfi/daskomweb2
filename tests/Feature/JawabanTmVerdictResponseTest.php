<?php

namespace Tests\Feature;

use App\Jobs\EvaluateAnswer;
use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\Asisten;
use App\Models\JawabanMandiri;
use App\Models\JawabanSubmission;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalMandiri;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use App\Services\Ai\AiClientManager;
use App\Services\Ai\AiGate;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * T23b — GET /api-v1/jawaban-mandiri/praktikan/{praktikan}/modul/{modul}
 * goes through the same App\Http\Resources\JawabanVerdictResource as TP
 * (SPEC §7.2, forMandiri()).
 *
 * Contract checked here:
 *  - same envelope: success → data.provider_status → data.submission →
 *    data.jawabanData[];
 *  - every answer carries ai = {status, verdict, alasan, max_score};
 *  - submission without answers → 200, every question `no_submission`;
 *  - one `key_pending` evaluation → ai.verdict null, `alasan` key present;
 *  - evaluations are matched on the ANSWER ROW id, never on soal_id;
 *  - the response never carries `ai_score` / `raw_response`.
 */
class JawabanTmVerdictResponseTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://gateway.test';

    private const CHAT_MODEL = 'google/gemini-flash';

    private const AI_KEYS = ['status', 'verdict', 'alasan', 'max_score'];

    private Asisten $approver;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->approver = $this->makeAsisten('tm-verdict-approver');

        config([
            'ai.openai_compatible.base_url' => self::BASE_URL,
            'ai.openai_compatible.api_key' => 'sk-test-secret-tm-verdict',
            'ai.openai_compatible.chat_model' => self::CHAT_MODEL,
            'ai.openai_compatible.embed_model' => 'google/gemini-embedding-001',
        ]);
    }

    // ---------------------------------------------------------------------
    // No submission
    // ---------------------------------------------------------------------

    public function test_submission_without_answers_returns_no_submission(): void
    {
        $modul = $this->modulWithAiConfig();
        $praktikan = Praktikan::factory()->create();
        $this->twoSoal($modul);

        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => JawabanSubmission::TIPE_TM,
            'attempt_no' => 1,
        ]);

        $this->actingAsAssistant();

        $this->getJson($this->endpoint($praktikan, $modul))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.submission.id', $submission->id)
            ->assertJsonPath('data.jawabanData.0.ai.status', 'no_submission')
            ->assertJsonPath('data.jawabanData.0.ai.verdict', null)
            ->assertJsonPath('data.jawabanData.0.jawaban', '-')
            ->assertJsonPath('data.jawabanData.1.ai.status', 'no_submission')
            ->assertJsonPath('data.jawabanData.1.ai.verdict', null);
    }

    public function test_praktikan_without_submission_returns_no_submission(): void
    {
        $modul = $this->modulWithAiConfig();
        $praktikan = Praktikan::factory()->create();
        $this->twoSoal($modul);

        $this->actingAsAssistant();

        $this->getJson($this->endpoint($praktikan, $modul))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.submission', null)
            ->assertJsonPath('data.jawabanData.0.ai.status', 'no_submission')
            ->assertJsonPath('data.jawabanData.1.ai.status', 'no_submission');
    }

    // ---------------------------------------------------------------------
    // Evaluations
    // ---------------------------------------------------------------------

    public function test_key_pending_evaluation_has_no_verdict_and_alasan_key(): void
    {
        Queue::fake();
        Http::fake();

        $modul = $this->modulWithAiConfig();
        $praktikan = Praktikan::factory()->create();
        [$soalA, $soalB] = $this->twoSoal($modul);
        $this->twoVersionItems($modul, $soalA, $soalB, SoalVersionItem::STATUS_PENDING);

        $submission = $this->submitTwoAnswers($praktikan, $modul, $soalA, $soalB);
        $this->runJobsFor($submission);

        Http::assertNothingSent();

        $this->actingAsAssistant();

        $response = $this->getJson($this->endpoint($praktikan, $modul));

        $response->assertOk()
            ->assertJsonPath('data.submission.id', $submission->id)
            ->assertJsonPath('data.jawabanData.0.ai.status', 'key_pending')
            ->assertJsonPath('data.jawabanData.0.ai.verdict', null)
            ->assertJsonMissingPath('data.jawabanData.0.ai.ai_score')
            ->assertJsonMissingPath('data.jawabanData.0.ai.raw_response');

        $ai = $response->json('data.jawabanData.0.ai');

        $this->assertSame(self::AI_KEYS, array_keys($ai));
        $this->assertArrayHasKey('alasan', $ai);

        $evaluation = AiEvaluation::query()
            ->where('jawaban_type', JawabanSubmission::TIPE_TM)
            ->orderBy('id')
            ->firstOrFail();

        $this->assertSame(AiEvaluation::STATUS_KEY_PENDING, $evaluation->status);
        $this->assertSame($evaluation->alasan, $ai['alasan']);
    }

    public function test_done_evaluation_is_shown_without_internal_numbers(): void
    {
        Queue::fake();
        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion((string) json_encode([
                    'verdict' => 'sesuai',
                    'score' => 95,
                    'alasan' => 'Jawaban sesuai dengan kunci jawaban.',
                ])),
                200,
            ),
        ]);

        $modul = $this->modulWithAiConfig();
        $praktikan = Praktikan::factory()->create();
        [$soalA, $soalB] = $this->twoSoal($modul);
        $this->twoVersionItems($modul, $soalA, $soalB, SoalVersionItem::STATUS_APPROVED);

        $submission = $this->submitTwoAnswers($praktikan, $modul, $soalA, $soalB);
        $this->runJobsFor($submission);

        $this->actingAsAssistant();

        $response = $this->getJson($this->endpoint($praktikan, $modul));

        $response->assertOk()
            ->assertJsonPath('data.jawabanData.0.soal_id', $soalA->id)
            ->assertJsonPath('data.jawabanData.0.jawaban', 'Menggunakan APD dan membaca SOP.')
            ->assertJsonPath('data.jawabanData.0.ai.status', 'done')
            ->assertJsonPath('data.jawabanData.0.ai.verdict', 'sesuai')
            ->assertJsonPath('data.jawabanData.0.ai.max_score', 100)
            ->assertJsonMissingPath('data.jawabanData.0.ai.ai_score')
            ->assertJsonMissingPath('data.jawabanData.1.ai.ai_score')
            ->assertDontSee('"ai_score"', false)
            ->assertDontSee('raw_response', false);

        foreach ($response->json('data.jawabanData') as $index => $row) {
            $this->assertSame(self::AI_KEYS, array_keys($row['ai']), "data.jawabanData.{$index}.ai keys");
        }
    }

    public function test_evaluation_is_matched_on_answer_row_id_not_soal_id(): void
    {
        Queue::fake();
        Http::fake();

        $modul = $this->modulWithAiConfig();
        $praktikan = Praktikan::factory()->create();
        [$soalA, $soalB] = $this->twoSoal($modul);
        $this->twoVersionItems($modul, $soalA, $soalB, SoalVersionItem::STATUS_PENDING);

        $submission = $this->submitTwoAnswers($praktikan, $modul, $soalA, $soalB);

        $answerA = JawabanMandiri::query()
            ->where('submission_id', $submission->id)
            ->where('soal_id', $soalA->id)
            ->sole();

        $evaluation = AiEvaluation::query()
            ->where('jawaban_type', JawabanSubmission::TIPE_TM)
            ->where('jawaban_id', $answerA->id)
            ->sole();

        $this->assertNotSame((int) $answerA->soal_id, (int) $answerA->id);

        $evaluation->forceFill([
            'jawaban_id' => (int) $answerA->soal_id,
            'status' => AiEvaluation::STATUS_KEY_PENDING,
        ])->save();

        $this->actingAsAssistant();

        $this->getJson($this->endpoint($praktikan, $modul))
            ->assertOk()
            ->assertJsonPath('data.jawabanData.0.soal_id', $soalA->id)
            ->assertJsonPath('data.jawabanData.0.ai.status', 'no_submission');

        $evaluation->forceFill(['jawaban_id' => (int) $answerA->id])->save();

        $this->getJson($this->endpoint($praktikan, $modul))
            ->assertOk()
            ->assertJsonPath('data.jawabanData.0.ai.status', 'key_pending')
            ->assertJsonPath('data.jawabanData.0.ai.verdict', null);
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    private function modulWithAiConfig(): Modul
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
     * @return array{0: SoalMandiri, 1: SoalMandiri}
     */
    private function twoSoal(Modul $modul): array
    {
        $soalA = SoalMandiri::factory()->create([
            'modul_id' => $modul->id,
            'soal' => 'Jelaskan prosedur keselamatan kerja.',
        ]);

        $soalB = SoalMandiri::factory()->create([
            'modul_id' => $modul->id,
            'soal' => 'Sebutkan alat pelindung diri.',
        ]);

        return [$soalA, $soalB];
    }

    private function twoVersionItems(Modul $modul, SoalMandiri $soalA, SoalMandiri $soalB, string $status): void
    {
        $versionSet = SoalVersionSet::query()->where('modul_id', $modul->id)->sole();
        $approved = $status === SoalVersionItem::STATUS_APPROVED;

        foreach ([$soalA, $soalB] as $soal) {
            SoalVersionItem::create([
                'version_set_id' => $versionSet->id,
                'soal_type' => SoalVersionItem::SOAL_TM,
                'soal_id' => $soal->id,
                'teks_snapshot' => $soal->soal,
                'bobot' => 50,
                'max_score' => 100,
                'jawaban_standar' => 'Kunci '.$soal->soal,
                'jawaban_standar_status' => $status,
                'jawaban_standar_approved_by' => $approved ? $this->approver->id : null,
                'jawaban_standar_approved_at' => $approved ? now() : null,
            ]);
        }
    }

    private function submitTwoAnswers(
        Praktikan $praktikan,
        Modul $modul,
        SoalMandiri $soalA,
        SoalMandiri $soalB,
    ): JawabanSubmission {
        $role = $this->roleNamed('tm-verdict-praktikan', 'praktikan');
        $role->givePermissionTo(Permission::firstOrCreate([
            'name' => 'praktikum-lms',
            'guard_name' => 'praktikan',
        ]));
        $praktikan->assignRole($role);

        $this->actingAs($praktikan, 'praktikan');

        $response = $this->postJson('/api-v1/jawaban-tm', [
            [
                'praktikan_id' => $praktikan->id,
                'modul_id' => $modul->id,
                'soal_id' => $soalA->id,
                'jawaban' => 'Menggunakan APD dan membaca SOP.',
            ],
            [
                'praktikan_id' => $praktikan->id,
                'modul_id' => $modul->id,
                'soal_id' => $soalB->id,
                'jawaban' => 'Helm, kacamata, dan sarung tangan.',
            ],
        ]);

        $response->assertCreated();

        Auth::guard('praktikan')->logout();

        return JawabanSubmission::query()->findOrFail($response->json('submission_id'));
    }

    /**
     * Run EvaluateAnswer::handle() for every evaluation row of the submission.
     */
    private function runJobsFor(JawabanSubmission $submission): void
    {
        $answerIds = JawabanMandiri::query()
            ->where('submission_id', $submission->id)
            ->pluck('id')
            ->all();

        $rows = AiEvaluation::query()
            ->where('jawaban_type', JawabanSubmission::TIPE_TM)
            ->whereIn('jawaban_id', $answerIds)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows, 'Chokepoint harus membuat dua baris ai_evaluations.');

        foreach ($rows as $row) {
            (new EvaluateAnswer($row))->handle(
                app(AiClientManager::class),
                app(AiGate::class),
                app(PromptBuilder::class),
            );
        }
    }

    private function actingAsAssistant(): Asisten
    {
        $role = $this->roleNamed('assistant-tm-verdict', 'asisten');
        $role->givePermissionTo(Permission::firstOrCreate([
            'name' => 'nilai-praktikan',
            'guard_name' => 'asisten',
        ]));

        $assistant = Asisten::factory()->create(['role_id' => $role->id]);
        $assistant->assignRole($role);

        $this->actingAs($assistant, 'asisten');

        return $assistant;
    }

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
        return "/api-v1/jawaban-mandiri/praktikan/{$praktikan->id}/modul/{$modul->id}";
    }

    /**
     * @return array<string, mixed>
     */
    private function chatCompletion(string $content): array
    {
        return [
            'id' => 'chatcmpl-tm-verdict-1',
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
