<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\JawabanMandiri;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalMandiri;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Regression: the submit endpoints never call the LLM.
 *
 * POST /api-v1/jawaban-tp and POST /api-v1/jawaban-tm must only persist the
 * answers and queue the evaluation work (EvaluateAnswer). The provider is
 * contacted later, from the job — never inside the request.
 *
 * Each case submits 5 answers through the HTTP endpoint with:
 *   - Http::fake()  → any outbound request is recorded, never real
 *   - Queue::fake() → no job runs inline
 *
 * and asserts:
 *   - 201 Created
 *   - Http::assertNothingSent()
 *   - the request took less than 300 ms
 *
 * The AI stack is fully enabled (active version set + allowed payload +
 * verified provider policy): even then the endpoint must not send anything,
 * so "0 requests" cannot be explained away by a disabled gate.
 */
class SubmissionNoLlmTest extends TestCase
{
    use RefreshDatabase;

    private const TP_ENDPOINT = '/api-v1/jawaban-tp';

    private const TM_ENDPOINT = '/api-v1/jawaban-tm';

    private const ANSWER_COUNT = 5;

    /** Endpoint wall-clock budget in seconds (300 ms). */
    private const MAX_DURATION_SECONDS = 0.3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        config([
            'ai.openai_compatible.base_url' => 'http://127.0.0.1:20128',
            'ai.openai_compatible.api_key' => 'sk-test-secret-no-llm',
            'ai.openai_compatible.chat_model' => 'gemini/gemini-flash',
        ]);
    }

    public function test_tp_submit_five_answers_never_touches_the_llm(): void
    {
        Http::fake();
        Queue::fake();

        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $this->versionSet($modul, now()->utc());
        $this->allowPayloadConfig();

        $answers = [];
        for ($i = 0; $i < self::ANSWER_COUNT; $i++) {
            $answers[] = [
                'praktikan_id' => $praktikan->id,
                'modul_id' => $modul->id,
                'soal_id' => SoalTp::factory()->for($modul)->create()->id,
                'jawaban' => 'Jawaban TP nomor '.$i.'.',
            ];
        }

        $response = $this->timedPost(self::TP_ENDPOINT, $answers);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('attempt_no', 1);

        Http::assertNothingSent();

        $this->assertSame(
            self::ANSWER_COUNT,
            JawabanTp::query()
                ->where('praktikan_id', $praktikan->id)
                ->count()
        );

        // The work is only queued; the 5 evaluations stay `queued` because
        // Queue::fake() keeps the job from running.
        $this->assertSame(
            self::ANSWER_COUNT,
            AiEvaluation::query()->where('status', AiEvaluation::STATUS_QUEUED)->count()
        );
    }

    public function test_tm_submit_five_answers_never_touches_the_llm(): void
    {
        Http::fake();
        Queue::fake();

        $praktikan = Praktikan::factory()->create();
        $modul = Modul::factory()->create();
        $this->versionSet($modul, now()->utc());
        $this->allowPayloadConfig();

        $answers = [];
        for ($i = 0; $i < self::ANSWER_COUNT; $i++) {
            $answers[] = [
                'praktikan_id' => $praktikan->id,
                'modul_id' => $modul->id,
                'soal_id' => SoalMandiri::factory()->for($modul)->create()->id,
                'jawaban' => 'Jawaban TM nomor '.$i.'.',
            ];
        }

        $response = $this->timedPost(self::TM_ENDPOINT, $answers);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('attempt_no', 1);

        Http::assertNothingSent();

        $this->assertSame(
            self::ANSWER_COUNT,
            JawabanMandiri::query()
                ->where('praktikan_id', $praktikan->id)
                ->count()
        );

        $this->assertSame(
            self::ANSWER_COUNT,
            AiEvaluation::query()->where('status', AiEvaluation::STATUS_QUEUED)->count()
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Send the submit request and assert it finished within the budget.
     *
     * @param  list<array{praktikan_id:int, modul_id:int, soal_id:int, jawaban:string}>  $answers
     */
    private function timedPost(string $endpoint, array $answers): TestResponse
    {
        $startedAt = microtime(true);

        $response = $this->postJson($endpoint, $answers);

        $duration = microtime(true) - $startedAt;

        $this->assertLessThan(
            self::MAX_DURATION_SECONDS,
            $duration,
            sprintf('%s memakan %.1f ms, batas %d ms.', $endpoint, $duration * 1000, self::MAX_DURATION_SECONDS * 1000)
        );

        return $response;
    }

    private function versionSet(Modul $modul, mixed $activatedAt): SoalVersionSet
    {
        $set = SoalVersionSet::query()->create([
            'modul_id' => $modul->id,
            'version_no' => 1,
            'status' => SoalVersionSet::STATUS_APPROVED,
            'bobot_total' => 100,
            'activated_at' => $activatedAt,
        ]);

        $soalTp = SoalTp::query()->where('modul_id', $modul->id)->get()->map(fn (SoalTp $soal): array => [
            'soal_type' => SoalVersionItem::SOAL_TP,
            'soal_id' => $soal->id,
            'teks_snapshot' => $soal->soal,
        ]);

        $soalTm = SoalMandiri::query()->where('modul_id', $modul->id)->get()->map(fn (SoalMandiri $soal): array => [
            'soal_type' => SoalVersionItem::SOAL_TM,
            'soal_id' => $soal->id,
            'teks_snapshot' => $soal->soal,
        ]);

        foreach ($soalTp->concat($soalTm) as $item) {
            SoalVersionItem::query()->create(array_merge($item, [
                'version_set_id' => $set->id,
                'bobot' => 100,
                'max_score' => 100,
                'jawaban_standar_status' => SoalVersionItem::STATUS_PENDING,
            ]));
        }

        return $set;
    }

    private function allowPayloadConfig(): AiConfig
    {
        DB::table('ai_configs')->update(['is_active' => false]);

        return AiConfig::query()->create([
            'driver' => 'openai_compatible',
            'retrieval_top_k' => 0,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'allow_external_payload' => true,
            'ai_enabled' => true,
            'budget_tokens_per_modul' => 100000,
            'provider_policy_verified_at' => now(),
            'is_active' => true,
        ]);
    }
}
