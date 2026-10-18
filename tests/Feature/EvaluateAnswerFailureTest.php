<?php

namespace Tests\Feature;

use App\Exceptions\AiUnavailableException;
use App\Jobs\EvaluateAnswer;
use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\Praktikan;
use App\Models\SoalTp;
use App\Models\SoalVersionItem;
use App\Models\SoalVersionSet;
use App\Services\Ai\AiClientManager;
use App\Services\Ai\AiGate;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeChatClient;
use Tests\Support\FakeChatClientManager;
use Tests\TestCase;
use Throwable;

/**
 * T22 — error handling of the EvaluateAnswer job.
 *
 * Every failure path must land on `failed` with both `error_code` and
 * `finished_at`, must never upgrade the row to `done`, and must not touch a
 * row the chokepoint already parked on `manual_required`.
 */
class EvaluateAnswerFailureTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://gateway.test';

    private const CHAT_MODEL = 'google/gemini-flash';

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
            'ai.openai_compatible.api_key' => 'sk-test-secret-failure',
            'ai.openai_compatible.chat_model' => self::CHAT_MODEL,
            'ai.openai_compatible.embed_model' => 'google/gemini-embedding-001',
        ]);

        $modul = Modul::factory()->create();
        $praktikan = Praktikan::factory()->create();

        $this->soal = SoalTp::factory()->create([
            'modul_id' => $modul->id,
            'soal' => 'Jelaskan fungsi resistor pada rangkaian seri.',
        ]);

        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => 'tp',
            'attempt_no' => 1,
        ]);

        $this->jawaban = JawabanTp::query()->create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $this->soal->id,
            'modul_id' => $modul->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Resistor membatasi arus listrik pada rangkaian.',
        ]);

        $this->versionSet = SoalVersionSet::create([
            'modul_id' => $modul->id,
            'version_no' => 1,
            'status' => SoalVersionSet::STATUS_APPROVED,
            'bobot_total' => 100,
            'activated_at' => now(),
        ]);

        $this->item = SoalVersionItem::create([
            'version_set_id' => $this->versionSet->id,
            'soal_type' => SoalVersionItem::SOAL_TP,
            'soal_id' => $this->soal->id,
            'bobot' => 100,
            'max_score' => 100,
            'teks_snapshot' => $this->soal->soal,
            'jawaban_standar' => 'Resistor membatasi arus listrik dan membagi tegangan.',
            'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
        ]);

        $this->config = AiConfig::query()->create([
            'driver' => 'openai_compatible',
            'base_url' => self::BASE_URL,
            'chat_model' => self::CHAT_MODEL,
            'retrieval_top_k' => 0, // RAG is covered by EvaluateAnswerRagTest.
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
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

    public function test_job_declares_retry_policy_on_the_job_properties(): void
    {
        $job = new EvaluateAnswer($this->queuedEvaluation());

        $this->assertSame(4, $job->tries);
        $this->assertSame([30, 120, 300], $job->backoff);
    }

    // ---------------------------------------------------------------------
    // Provider unavailable
    // ---------------------------------------------------------------------

    public function test_ai_unavailable_exception_marks_row_failed_external_unavailable(): void
    {
        Http::fake();

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation, new FakeChatClient(
            AiUnavailableException::unavailable(),
        ), expectThrow: true);

        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'failed',
            'error_code' => 'EXTERNAL_UNAVAILABLE',
        ]);

        $this->assertNotNull($evaluation->fresh()->finished_at);
        Http::assertNothingSent();
    }

    public function test_connection_exception_marks_row_failed_external_unavailable(): void
    {
        Http::fake();

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation, new FakeChatClient(
            new ConnectionException('cURL error 7: Failed to connect'),
        ), expectThrow: true);

        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'failed',
            'error_code' => 'EXTERNAL_UNAVAILABLE',
        ]);

        $this->assertNotNull($evaluation->fresh()->finished_at);
        Http::assertNothingSent();
    }

    public function test_provider_503_is_recorded_as_external_unavailable(): void
    {
        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                ['error' => ['message' => 'All accounts unavailable']],
                503
            ),
        ]);

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation, null, expectThrow: true);

        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'failed',
            'error_code' => 'EXTERNAL_UNAVAILABLE',
        ]);

        $this->assertDatabaseHas('ai_evaluation_attempts', [
            'ai_evaluation_id' => $evaluation->getKey(),
            'attempt_no' => 1,
            'http_status' => 503,
            'outcome' => 'failed',
            'error_code' => 'EXTERNAL_UNAVAILABLE',
        ]);
    }

    // ---------------------------------------------------------------------
    // Invalid model output
    // ---------------------------------------------------------------------

    public function test_non_json_output_marks_row_failed_invalid_json(): void
    {
        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion('Jawaban praktikan sudah sesuai, skor 90.'),
                200
            ),
        ]);

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $fresh = $evaluation->fresh();
        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'failed',
            'error_code' => 'INVALID_JSON',
        ]);
        $this->assertNotSame(AiEvaluation::STATUS_DONE, $fresh->status);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNull($fresh->ai_score);

        $this->assertDatabaseHas('ai_evaluation_attempts', [
            'ai_evaluation_id' => $evaluation->getKey(),
            'attempt_no' => 1,
            'http_status' => 200,
            'outcome' => 'failed',
            'error_code' => 'INVALID_JSON',
        ]);

        Http::assertSentCount(1);
    }

    public function test_json_without_a_known_verdict_is_invalid_json(): void
    {
        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion('{"verdict":"bagus sekali","score":90}'),
                200
            ),
        ]);

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'failed',
            'error_code' => 'INVALID_JSON',
        ]);
    }

    // ---------------------------------------------------------------------
    // Retries exhausted
    // ---------------------------------------------------------------------

    public function test_row_stays_failed_after_every_try_is_spent(): void
    {
        Http::fake();

        $failure = new ConnectionException('cURL error 28: timed out');
        $client = new FakeChatClient($failure);
        $evaluation = $this->queuedEvaluation();

        $job = new EvaluateAnswer($evaluation);

        for ($try = 1; $try <= $job->tries; $try++) {
            // A retry is a fresh run of the same row, so it starts from the
            // retried state (the row was `queued`, then failed once already).
            $row = AiEvaluation::query()->findOrFail($evaluation->getKey());
            $row->forceFill(['status' => $try === 1 ? 'queued' : 'processing'])->save();

            $this->runJob($row, $client, expectThrow: true);

            $fresh = $evaluation->fresh();
            $this->assertSame(AiEvaluation::STATUS_FAILED, $fresh->status, "Percobaan {$try} harus gagal.");
            $this->assertSame('EXTERNAL_UNAVAILABLE', $fresh->error_code);
            $this->assertNotNull($fresh->finished_at);
            $this->assertDatabaseHas('ai_evaluation_attempts', [
                'ai_evaluation_id' => $evaluation->getKey(),
                'attempt_no' => $try,
                'outcome' => 'failed',
                'error_code' => 'EXTERNAL_UNAVAILABLE',
            ]);
        }

        // Last attempt spent → the queue calls failed(); the row must still be
        // failed (never done) with its code and finish time.
        $job->failed($failure);

        $fresh = $evaluation->fresh();
        $this->assertSame(AiEvaluation::STATUS_FAILED, $fresh->status);
        $this->assertNotSame(AiEvaluation::STATUS_DONE, $fresh->status);
        $this->assertSame('EXTERNAL_UNAVAILABLE', $fresh->error_code);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNull($fresh->ai_score);
        $this->assertSame($job->tries, $fresh->attempts()->count());

        // The failed row is terminal: a late redelivery changes nothing.
        $this->runJob($fresh, $client);
        $this->assertSame(AiEvaluation::STATUS_FAILED, $evaluation->fresh()->status);
        $this->assertSame($job->tries, $evaluation->fresh()->attempts()->count());

        Http::assertNothingSent();
    }

    public function test_failed_hook_never_overwrites_a_done_row(): void
    {
        Http::fake();

        $evaluation = $this->queuedEvaluation([
            'status' => AiEvaluation::STATUS_DONE,
            'ai_score' => 80,
            'finished_at' => now(),
        ]);

        (new EvaluateAnswer($evaluation))->failed(new ConnectionException('late failure'));

        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'done',
            'error_code' => null,
        ]);
    }

    // ---------------------------------------------------------------------
    // manual_required is owned by the chokepoint (T23a)
    // ---------------------------------------------------------------------

    public function test_manual_required_row_is_a_no_op(): void
    {
        Http::fake();

        $evaluation = $this->queuedEvaluation([
            'status' => AiEvaluation::STATUS_MANUAL_REQUIRED,
            'answer_hash' => hash('sha256', 'lampiran'),
        ]);

        $this->runJob($evaluation);

        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'manual_required',
            'error_code' => null,
            'finished_at' => null,
        ]);

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
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

    /**
     * Run handle() directly; a retryable failure is expected to propagate
     * (that is the queue's retry signal), anything else must stay inside.
     */
    private function runJob(AiEvaluation $evaluation, ?AiClient $client = null, bool $expectThrow = false): void
    {
        $manager = $client === null ? app(AiClientManager::class) : $this->managerReturning($client);
        $job = new EvaluateAnswer($evaluation);

        try {
            $job->handle($manager, app(AiGate::class), app(PromptBuilder::class));
        } catch (Throwable $exception) {
            $this->assertTrue($expectThrow, 'Job tidak boleh melempar '.$exception::class.': '.$exception->getMessage());

            return;
        }

        $this->assertFalse($expectThrow, 'Job seharusnya meneruskan kegagalan provider agar antrean mengulang.');
    }

    private function managerReturning(AiClient $client): AiClientManager
    {
        return new FakeChatClientManager($client);
    }

    /**
     * @return array<string, mixed>
     */
    private function chatCompletion(string $content): array
    {
        return [
            'id' => 'chatcmpl-failure-1',
            'object' => 'chat.completion',
            'model' => self::CHAT_MODEL,
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ],
            ],
        ];
    }
}
