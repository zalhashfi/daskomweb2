<?php

namespace Tests\Feature;

use App\Jobs\EvaluateAnswer;
use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\JawabanMandiri;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalMandiri;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use App\Services\Ai\AiClientManager;
use App\Services\Ai\AiGate;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T21 — EvaluateAnswer job + PromptBuilder.
 *
 * The job must consume an existing ai_evaluations row (never insert one),
 * decide every abort path without a provider call, and only then talk to the
 * gateway. Assertions observe DB state and outbound traffic
 * (Http::assertNothingSent / assertSentCount), never private methods.
 */
class EvaluateAnswerJobTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://gateway.test';

    private const CHAT_MODEL = 'google/gemini-flash';

    private const MAX_ANSWER_CHARS = 10000;

    private SoalTp $soal;

    private JawabanTp $jawaban;

    private SoalVersionSet $versionSet;

    private SoalVersionItem $item;

    private AiConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        Auth::guard('web')->logout();

        config([
            'ai.openai_compatible.base_url' => self::BASE_URL,
            'ai.openai_compatible.api_key' => 'sk-test-secret-job',
            'ai.openai_compatible.chat_model' => self::CHAT_MODEL,
            'ai.openai_compatible.embed_model' => 'google/gemini-embedding-001',
        ]);

        $modul = Modul::factory()->create();
        $praktikan = Praktikan::factory()->create();

        $this->soal = SoalTp::factory()->create([
            'modul_id' => $modul->id,
            'soal' => 'Jelaskan fungsi resistor pada rangkaian seri.',
        ]);

        $this->jawaban = JawabanTp::query()->create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $this->soal->id,
            'modul_id' => $modul->id,
            'submission_id' => $this->submission($praktikan, $modul, 'tp')->id,
            'jawaban' => 'Resistor membatasi arus listrik pada rangkaian.',
        ]);

        $this->versionSet = SoalVersionSet::create([
            'modul_id' => $modul->id,
            'version_no' => 1,
            'status' => SoalVersionSet::STATUS_APPROVED,
            'bobot_total' => 100,
            'activated_at' => now(),
        ]);

        $this->item = $this->makeItem([
            'jawaban_standar' => 'Resistor membatasi arus listrik dan membagi tegangan.',
            'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
            'max_score' => 100,
        ]);

        $this->config = AiConfig::query()->create([
            'driver' => 'openai_compatible',
            'base_url' => self::BASE_URL,
            'chat_model' => self::CHAT_MODEL,
            'retrieval_top_k' => 4,
            'max_context_tokens' => 4000,
            'max_answer_chars' => self::MAX_ANSWER_CHARS,
            'allow_external_payload' => true,
            'ai_enabled' => true,
            'budget_tokens_per_modul' => 500000,
            'provider_policy_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------------
    // Job configuration
    // ---------------------------------------------------------------------

    public function test_job_declares_retry_policy(): void
    {
        $job = new EvaluateAnswer($this->queuedEvaluation());

        $this->assertSame(4, $job->tries);
        $this->assertSame([30, 120, 300], $job->backoff);
        $this->assertSame(90, $job->timeout);
    }

    // ---------------------------------------------------------------------
    // No-op / guard paths (0 request)
    // ---------------------------------------------------------------------

    public function test_terminal_row_is_a_no_op(): void
    {
        Http::fake();

        foreach ([
            AiEvaluation::STATUS_DONE,
            AiEvaluation::STATUS_FAILED,
            AiEvaluation::STATUS_KEY_PENDING,
            AiEvaluation::STATUS_MANUAL_REQUIRED,
        ] as $status) {
            $evaluation = $this->queuedEvaluation([
                'status' => $status,
                'answer_hash' => hash('sha256', 'terminal-'.$status),
            ]);

            $this->runJob($evaluation);

            $this->assertSame($status, $evaluation->fresh()->status, "Status {$status} tidak boleh berubah.");
        }

        Http::assertNothingSent();
    }

    public function test_gate_disabled_marks_row_failed_without_request(): void
    {
        Http::fake();

        $this->config->forceFill(['ai_enabled' => false])->save();
        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $this->assertSame(AiEvaluation::STATUS_FAILED, $evaluation->fresh()->status);
        $this->assertNull($evaluation->fresh()->ai_score);
        Http::assertNothingSent();
    }

    public function test_payload_policy_disabled_fails_closed(): void
    {
        Http::fake();

        // Revision insert keeps the row pointing at a snapshot that denies
        // outbound payloads (spec E26).
        $this->config->forceFill(['allow_external_payload' => false])->save();

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $this->assertSame(AiEvaluation::STATUS_FAILED, $evaluation->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_unapproved_key_moves_the_same_row_to_key_pending(): void
    {
        Http::fake();

        // forceFill skips the approval observer so the kunci stays pending.
        $this->item->forceFill(['jawaban_standar_status' => SoalVersionItem::STATUS_PENDING])->saveQuietly();
        $evaluation = $this->queuedEvaluation();

        $countBefore = AiEvaluation::query()->count();

        $this->runJob($evaluation);

        $this->assertSame(AiEvaluation::STATUS_KEY_PENDING, $evaluation->fresh()->status);
        $this->assertSame($countBefore, AiEvaluation::query()->count(), 'Job tidak boleh membuat baris baru.');
        $this->assertSame(1, AiEvaluation::query()->count());
        Http::assertNothingSent();
    }

    public function test_missing_key_row_is_key_pending(): void
    {
        Http::fake();

        $this->item->delete();
        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $this->assertSame(AiEvaluation::STATUS_KEY_PENDING, $evaluation->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_dash_answer_is_tidak_menjawab_without_request(): void
    {
        Http::fake();

        $this->jawaban->forceFill(['jawaban' => '-'])->save();
        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $fresh = $evaluation->fresh();
        $this->assertSame(AiEvaluation::STATUS_DONE, $fresh->status);
        $this->assertSame('0.00', $fresh->ai_score);
        $this->assertSame('tidak_menjawab', json_decode((string) $fresh->raw_response, true)['verdict'] ?? null);
        Http::assertNothingSent();
    }

    public function test_empty_answer_is_tidak_menjawab_without_request(): void
    {
        Http::fake();

        $this->jawaban->forceFill(['jawaban' => '   '])->save();
        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $this->assertSame(AiEvaluation::STATUS_DONE, $evaluation->fresh()->status);
        $this->assertSame('0.00', $evaluation->fresh()->ai_score);
        Http::assertNothingSent();
    }

    public function test_token_estimate_over_context_limit_fails_before_sending(): void
    {
        Http::fake();

        $this->config->forceFill(['max_context_tokens' => 10])->save();
        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $this->assertSame(AiEvaluation::STATUS_FAILED, $evaluation->fresh()->status);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // Prompt / truncation
    // ---------------------------------------------------------------------

    public function test_long_answer_is_truncated_and_hashed(): void
    {
        $prompt = null;

        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => function (Request $request) use (&$prompt) {
                $prompt = $request->data()['messages'][1]['content'] ?? '';

                return Http::response($this->chatCompletion($this->verdictJson('sesuai', 90)), 200);
            },
        ]);

        $this->config->forceFill(['max_context_tokens' => 100000])->save();

        $this->jawaban->forceFill(['jawaban' => str_repeat('a', 12000)])->save();
        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $sent = $this->sentAnswerBetween($prompt);

        $this->assertStringContainsString(EvaluateAnswer::TRUNCATION_MARKER, $prompt);
        $this->assertStringNotContainsString(str_repeat('a', self::MAX_ANSWER_CHARS + 1), $prompt);
        $this->assertSame(hash('sha256', $sent), $evaluation->fresh()->answer_hash);
        $this->assertSame(AiEvaluation::STATUS_DONE, $evaluation->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_prompt_has_delimiters_injection_rules_and_no_identity(): void
    {
        $payload = null;

        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => function (Request $request) use (&$payload) {
                $payload = $request->data();

                return Http::response($this->chatCompletion($this->verdictJson('sesuai', 95)), 200);
            },
        ]);

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $system = $payload['messages'][0]['content'];
        $user = $payload['messages'][1]['content'];

        $this->assertSame('system', $payload['messages'][0]['role']);
        $this->assertStringContainsString('<jawaban_praktikan>', $user);
        $this->assertStringContainsString('</jawaban_praktikan>', $user);
        $this->assertStringContainsString('Resistor membatasi arus listrik dan membagi tegangan.', $user);
        $this->assertStringContainsString('bukan instruksi', $system);
        $this->assertStringContainsString('JSON', $system);
        $this->assertStringContainsString('jawaban_praktikan', $user);
        $this->assertStringNotContainsString((string) $this->jawaban->praktikan_id, $user.$system);
        $this->assertStringNotContainsString((string) $this->jawaban->praktikan->nim, $user.$system);
        $this->assertStringNotContainsString($this->jawaban->praktikan->nama, $user.$system);
        $this->assertSame(0, PromptBuilder::RETRIEVAL_TOP_K);
    }

    // ---------------------------------------------------------------------
    // Happy path
    // ---------------------------------------------------------------------

    public function test_valid_json_marks_evaluation_done(): void
    {
        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion($this->verdictJson('berbeda', 40), ['usage' => [
                    'prompt_tokens' => 512,
                    'completion_tokens' => 32,
                ]]),
                200
            ),
        ]);

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $fresh = $evaluation->fresh();
        $this->assertSame(AiEvaluation::STATUS_DONE, $fresh->status);
        $this->assertSame('40.00', $fresh->ai_score);
        $this->assertSame(512, $fresh->tokens_in);
        $this->assertSame(32, $fresh->tokens_out);
        $this->assertSame('google/gemini-flash', $fresh->response_model);
        $this->assertFalse($fresh->model_drift);
        $this->assertSame('openai_compatible', $fresh->driver);
        $this->assertNotNull($fresh->raw_response);

        $this->assertDatabaseHas('ai_evaluation_attempts', [
            'ai_evaluation_id' => $evaluation->getKey(),
            'attempt_no' => 1,
            'http_status' => 200,
            'outcome' => 'done',
        ]);

        Http::assertSentCount(1);
    }

    public function test_score_above_max_is_clamped(): void
    {
        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion($this->verdictJson('sesuai', 150)),
                200
            ),
        ]);

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $this->assertSame(AiEvaluation::STATUS_DONE, $evaluation->fresh()->status);
        $this->assertSame('100.00', $evaluation->fresh()->ai_score);
        $this->assertSame(100, $evaluation->fresh()->max_score);
    }

    public function test_negative_score_is_clamped_to_zero(): void
    {
        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion($this->verdictJson('berbeda', -25)),
                200
            ),
        ]);

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $this->assertSame('0.00', $evaluation->fresh()->ai_score);
    }

    public function test_model_drift_is_recorded_but_still_done(): void
    {
        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion($this->verdictJson('sesuai', 88), ['model' => 'other/vendor-model']),
                200
            ),
        ]);

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $fresh = $evaluation->fresh();
        $this->assertSame(AiEvaluation::STATUS_DONE, $fresh->status);
        $this->assertTrue($fresh->model_drift);
        $this->assertSame('other/vendor-model', $fresh->response_model);

        $this->assertDatabaseHas('ai_evaluation_attempts', [
            'ai_evaluation_id' => $evaluation->getKey(),
            'response_model' => 'other/vendor-model',
            'outcome' => 'done',
        ]);
    }

    public function test_tm_answer_uses_the_mandiri_table(): void
    {
        $modul = Modul::query()->findOrFail($this->soal->modul_id);
        $soal = SoalMandiri::factory()->create(['modul_id' => $modul->id]);
        $jawaban = JawabanMandiri::query()->create([
            'praktikan_id' => $this->jawaban->praktikan_id,
            'soal_id' => $soal->id,
            'modul_id' => $modul->id,
            'submission_id' => $this->submission($this->jawaban->praktikan, $modul, 'tm')->id,
            'jawaban' => 'Jawaban tugas mandiri.',
        ]);

        SoalVersionItem::create([
            'version_set_id' => $this->versionSet->id,
            'soal_type' => SoalVersionItem::SOAL_TM,
            'soal_id' => $soal->id,
            'bobot' => 0,
            'max_score' => 100,
            'teks_snapshot' => $soal->soal,
            'jawaban_standar' => 'Kunci tugas mandiri.',
            'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
        ]);

        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion($this->verdictJson('sesuai', 70)),
                200
            ),
        ]);

        $evaluation = $this->queuedEvaluation([
            'jawaban_id' => $jawaban->id,
            'jawaban_type' => 'tm',
        ]);

        $this->runJob($evaluation);

        $this->assertSame(AiEvaluation::STATUS_DONE, $evaluation->fresh()->status);
        $this->assertSame('70.00', $evaluation->fresh()->ai_score);
    }

    // ---------------------------------------------------------------------
    // Row ownership
    // ---------------------------------------------------------------------

    public function test_job_never_creates_evaluation_rows(): void
    {
        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion($this->verdictJson('sesuai', 80)),
                200
            ),
        ]);

        $evaluation = $this->queuedEvaluation();
        $countBefore = AiEvaluation::query()->count();

        $this->runJob($evaluation);
        $this->runJob($evaluation->fresh());

        $this->assertSame($countBefore, AiEvaluation::query()->count());
        $this->assertSame(1, AiEvaluation::query()->count());
        $this->assertSame(AiEvaluation::STATUS_DONE, $evaluation->fresh()->status);

        // Second run sees a terminal row → no second provider call.
        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function submission(Praktikan $praktikan, Modul $modul, string $tipe): JawabanSubmission
    {
        return JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => $tipe,
            'attempt_no' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeItem(array $attributes): SoalVersionItem
    {
        return SoalVersionItem::create(array_merge([
            'version_set_id' => $this->versionSet->id,
            'soal_type' => SoalVersionItem::SOAL_TP,
            'soal_id' => $this->soal->id,
            'bobot' => 100,
            'max_score' => 100,
            'teks_snapshot' => $this->soal->soal,
        ], $attributes));
    }

    /**
     * A `queued` row exactly as the chokepoint (T23a) would have created it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function queuedEvaluation(array $attributes = []): AiEvaluation
    {
        return AiEvaluation::query()->create(array_merge([
            'jawaban_id' => $this->jawaban->id,
            'jawaban_type' => 'tp',
            'answer_hash' => hash('sha256', (string) $this->jawaban->jawaban),
            'version_set_id' => $this->versionSet->id,
            'ai_config_id' => $this->config->id,
            'status' => 'queued',
        ], $attributes));
    }

    private function runJob(AiEvaluation $evaluation): void
    {
        // Called directly (not dispatched) so the assertions observe handle().
        (new EvaluateAnswer($evaluation))->handle(
            app(AiClientManager::class),
            app(AiGate::class),
            app(PromptBuilder::class),
        );
    }

    private function verdictJson(string $verdict, int|float $score): string
    {
        return (string) json_encode([
            'verdict' => $verdict,
            'score' => $score,
            'confidence' => 0.82,
            'alasan' => 'Dinilai terhadap kunci jawaban.',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function chatCompletion(string $content, array $overrides = []): array
    {
        return array_merge([
            'id' => 'chatcmpl-eval-1',
            'object' => 'chat.completion',
            'model' => self::CHAT_MODEL,
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ],
            ],
        ], $overrides);
    }

    /**
     * Extract the text the job placed between the answer delimiters.
     */
    private function sentAnswerBetween(?string $prompt): string
    {
        $this->assertIsString($prompt, 'Prompt harus terkirim ke provider.');
        $this->assertMatchesRegularExpression(
            '/<jawaban_praktikan>\n(.*)\n<\/jawaban_praktikan>/s',
            $prompt
        );

        preg_match('/<jawaban_praktikan>\n(.*)\n<\/jawaban_praktikan>/s', $prompt, $matches);

        return $matches[1];
    }
}
