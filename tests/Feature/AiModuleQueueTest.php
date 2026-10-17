<?php

namespace Tests\Feature;

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
use App\Services\Ai\ModuleQueueGuard;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T22d — FIFO satu modul.
 *
 * Only the oldest modul (by the minimum `created_at` of its non-terminal
 * evaluations) may reach the provider. A question of any other modul stays
 * `queued`, makes zero provider requests and goes back onto the queue with a
 * delay. Assertions observe DB state, outbound traffic and the queue push of
 * `release()`, never private methods.
 */
class AiModuleQueueTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://gateway.test';

    private const CHAT_MODEL = 'google/gemini-flash';

    private Modul $modulA;

    private Modul $modulB;

    private ?Modul $modulC = null;

    private AiConfig $config;

    private \Carbon\CarbonImmutable $frozenAt;

    protected function setUp(): void
    {
        parent::setUp();

        Auth::guard('web')->logout();

        $this->frozenAt = \Carbon\CarbonImmutable::now();
        $this->travelTo($this->frozenAt);

        config([
            'ai.openai_compatible.base_url' => self::BASE_URL,
            'ai.openai_compatible.api_key' => 'sk-test-secret-queue',
            'ai.openai_compatible.chat_model' => self::CHAT_MODEL,
            'ai.openai_compatible.embed_model' => 'google/gemini-embedding-001',
        ]);

        $this->modulA = Modul::factory()->create();
        $this->modulB = Modul::factory()->create();

        $this->config = AiConfig::query()->create([
            'driver' => 'openai_compatible',
            'base_url' => self::BASE_URL,
            'chat_model' => self::CHAT_MODEL,
            'retrieval_top_k' => 0,
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
    // Acceptance
    // ---------------------------------------------------------------------

    public function test_module_b_stays_queued_while_module_a_is_active(): void
    {
        Http::fake([self::BASE_URL.'/v1/chat/completions' => Http::response($this->chatCompletion(), 200)]);

        $evalA = $this->queuedEvaluation($this->modulA);
        $this->ageRow($evalA, 10800);
        $evalB = $this->queuedEvaluation($this->modulB);
        $this->ageRow($evalB, 3600);

        // A is older → A runs.
        $this->runJob($evalA);
        $this->assertSame(AiEvaluation::STATUS_DONE, $evalA->fresh()->status);

        // A still has a running row → B must not start.
        $evalA2 = $this->queuedEvaluation($this->modulA, ['answer_hash' => hash('sha256', 'a-running')]);
        $evalA2->forceFill(['status' => 'running'])->save();
        $this->ageRow($evalA2, 7200);

        $this->runJob($evalB);

        $this->assertSame('queued', $evalB->fresh()->status);
        $this->assertNull($evalB->fresh()->finished_at);
        $this->assertSame(1, Http::recorded()->count(), 'Hanya A yang boleh mengirim request.');

        // After every A row is terminal, B starts.
        $evalA2->forceFill([
            'status' => AiEvaluation::STATUS_DONE,
            'finished_at' => now(),
        ])->save();

        $this->assertFalse(app(ModuleQueueGuard::class)->shouldYield($this->modulB->id));
        $this->assertSame($this->modulB->id, app(ModuleQueueGuard::class)->activeModulId());

        $this->runJob($evalB);

        $this->assertSame(AiEvaluation::STATUS_DONE, $evalB->fresh()->status);
        $this->assertSame(2, Http::recorded()->count());
        $prompts = Http::recorded()
            ->map(fn ($pair) => (string) ($pair[0]->data()['messages'][1]['content'] ?? ''))
            ->all();
        $this->assertStringContainsString(
            '<jawaban_praktikan>'."\n".$this->promptAnswerFor($evalB)."\n".'</jawaban_praktikan>',
            $prompts[1] ?? '',
            'Request kedua harus membawa jawaban modul B.',
        );
    }

    public function test_module_b_submission_never_sends_a_request(): void
    {
        Http::fake();

        $evalA = $this->queuedEvaluation($this->modulA);
        $this->ageRow($evalA, 10800);
        $evalB1 = $this->queuedEvaluation($this->modulB);
        $this->ageRow($evalB1, 7200);
        $evalB2 = $this->queuedEvaluation($this->modulB, ['answer_hash' => hash('sha256', 'b-2')]);
        $this->ageRow($evalB2, 3600);

        $this->runJob($evalB1);
        $this->runJob($evalB2);

        $this->assertSame('queued', $evalB1->fresh()->status);
        $this->assertSame('queued', $evalB2->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_two_modules_never_run_concurrently(): void
    {
        Http::fake();

        $evalA = $this->queuedEvaluation($this->modulA);
        $evalA->forceFill(['status' => 'running'])->save();
        $this->ageRow($evalA, 10800);
        $evalB = $this->queuedEvaluation($this->modulB);
        $this->ageRow($evalB, 3600);

        $this->runJob($evalB);

        $this->assertSame('queued', $evalB->fresh()->status);
        $this->assertSame('running', $evalA->fresh()->status);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // Guard contract
    // ---------------------------------------------------------------------

    public function test_guard_head_of_queue_is_the_oldest_module(): void
    {
        $guard = app(ModuleQueueGuard::class);

        // B's row is the oldest non-terminal row → B is the head even though
        // its evaluation was inserted after A's.
        $evalA = $this->queuedEvaluation($this->modulA);
        $this->ageRow($evalA, 5);
        $evalB = $this->queuedEvaluation($this->modulB);
        $this->ageRow($evalB, 60);

        $this->assertSame($this->modulB->id, $guard->activeModulId());
        $this->assertTrue($guard->shouldYield($this->modulA->id));
        $this->assertFalse($guard->shouldYield($this->modulB->id));
    }

    public function test_guard_orders_by_oldest_queued_at_across_three_modules(): void
    {
        $guard = app(ModuleQueueGuard::class);
        $this->modulC = Modul::factory()->create();

        $evalA = $this->queuedEvaluation($this->modulA);
        $this->ageRow($evalA, 3600);
        $evalB = $this->queuedEvaluation($this->modulB);
        $this->ageRow($evalB, 600);
        $evalC = $this->queuedEvaluation($this->modulC);
        $this->ageRow($evalC, 1200);

        // A (oldest) → C → B.
        $this->assertSame($this->modulA->id, $guard->activeModulId());
        $this->assertTrue($guard->shouldYield($this->modulB->id));
        $this->assertTrue($guard->shouldYield($this->modulC->id));

        $evalA->forceFill(['status' => AiEvaluation::STATUS_DONE, 'finished_at' => now()])->save();

        $this->assertSame($this->modulC->id, $guard->activeModulId());
        $this->assertFalse($guard->shouldYield($this->modulC->id));
        $this->assertTrue($guard->shouldYield($this->modulB->id));

        $evalC->forceFill(['status' => AiEvaluation::STATUS_FAILED, 'finished_at' => now()])->save();

        $this->assertSame($this->modulB->id, $guard->activeModulId());
        $this->assertFalse($guard->shouldYield($this->modulB->id));
    }

    public function test_guard_ignores_terminal_rows(): void
    {
        $guard = app(ModuleQueueGuard::class);

        foreach ([AiEvaluation::STATUS_DONE, AiEvaluation::STATUS_FAILED, AiEvaluation::STATUS_MANUAL_REQUIRED, AiEvaluation::STATUS_KEY_PENDING] as $status) {
            $this->queuedEvaluation($this->modulA, [
                'status' => $status,
                'answer_hash' => hash('sha256', 'terminal-'.$status),
            ]);
        }

        $this->assertNull($guard->activeModulId());
        $this->assertNull($guard->oldestQueuedAt($this->modulA->id));
        $this->assertFalse($guard->shouldYield($this->modulA->id), 'Antrean kosong tidak pernah menahan job.');

        $evalB = $this->queuedEvaluation($this->modulB);
        $this->assertSame($this->modulB->id, $guard->activeModulId());
        $this->assertFalse($guard->shouldYield($this->modulB->id));
        $this->assertSame('queued', $evalB->fresh()->status);
    }

    public function test_guard_never_blocks_a_module_behind_its_own_rows(): void
    {
        $guard = app(ModuleQueueGuard::class);

        $this->queuedEvaluation($this->modulA);
        $evalA2 = $this->queuedEvaluation($this->modulA, ['answer_hash' => hash('sha256', 'a-2')]);

        $this->assertFalse($guard->shouldYield($this->modulA->id));

        // Both rows of the head module run, in turn.
        Http::fake([self::BASE_URL.'/v1/chat/completions' => Http::response($this->chatCompletion(), 200)]);

        $this->runJob(AiEvaluation::query()->where('status', 'queued')->orderBy('id')->firstOrFail());
        $this->runJob($evalA2);

        $this->assertSame(2, Http::recorded()->count());
        $this->assertSame(2, AiEvaluation::query()->where('status', AiEvaluation::STATUS_DONE)->count());
    }

    public function test_released_job_keeps_row_queued_and_records_the_delay(): void
    {
        Http::fake();

        $this->queuedEvaluation($this->modulA);
        $evalB = $this->queuedEvaluation($this->modulB);

        $job = new EvaluateAnswer($evalB);
        // Fakes the queue job so release() is recorded instead of lost.
        $job->withFakeQueueInteractions();
        $job->handle(
            app(AiClientManager::class),
            app(AiGate::class),
            app(PromptBuilder::class),
            app(ModuleQueueGuard::class),
        );

        $fresh = $evalB->fresh();
        // release() keeps the row as it is and only pushes the job back.
        $this->assertSame('queued', $fresh->status);
        $this->assertNull($fresh->finished_at);
        $this->assertNull($fresh->error_code);
        $this->assertCount(0, $fresh->attempts()->get(), 'Tidak boleh ada attempt/request untuk B.');
        $job->assertReleased(ModuleQueueGuard::RELEASE_DELAY_SECONDS);
        $job->assertNotDeleted();
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function queuedEvaluation(Modul $modul, array $attributes = []): AiEvaluation
    {
        $praktikan = Praktikan::factory()->create();
        $soal = SoalTp::factory()->create(['modul_id' => $modul->id]);
        $versionSet = $this->versionSetFor($modul);

        SoalVersionItem::create([
            'version_set_id' => $versionSet->id,
            'soal_type' => SoalVersionItem::SOAL_TP,
            'soal_id' => $soal->id,
            'bobot' => 100,
            'max_score' => 100,
            'teks_snapshot' => $soal->soal,
            'jawaban_standar' => 'Kunci jawaban modul '.$modul->id.'.',
            'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
        ]);

        $submission = JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $modul->id,
            'tipe' => 'tp',
            'attempt_no' => 1,
        ]);

        $jawaban = JawabanTp::query()->create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $soal->id,
            'modul_id' => $modul->id,
            'submission_id' => $submission->id,
            'jawaban' => 'Jawaban '.$soal->id.' untuk modul '.$modul->id.'.',
        ]);

        return AiEvaluation::query()->create(array_merge([
            'jawaban_id' => $jawaban->id,
            'jawaban_type' => 'tp',
            'answer_hash' => hash('sha256', 'modul-'.$modul->id.'-'.$jawaban->id),
            'version_set_id' => $versionSet->id,
            'ai_config_id' => $this->config->id,
            'status' => 'queued',
        ], $attributes));
    }

    private function versionSetFor(Modul $modul): SoalVersionSet
    {
        return SoalVersionSet::query()->firstOrCreate(
            ['modul_id' => $modul->id, 'version_no' => 1],
            [
                'status' => SoalVersionSet::STATUS_APPROVED,
                'bobot_total' => 100,
                'activated_at' => now(),
            ],
        );
    }

    /**
     * Backdate `created_at`; all rows share one frozen "now" per test, so
     * the ordering is exact regardless of wall-clock time.
     */
    private function ageRow(AiEvaluation $evaluation, int $seconds): void
    {
        AiEvaluation::query()->whereKey($evaluation->getKey())
            ->update(['created_at' => $this->frozenAt->copy()->subSeconds($seconds)]);
    }

    private function runJob(AiEvaluation $evaluation): void
    {
        (new EvaluateAnswer($evaluation))->handle(
            app(AiClientManager::class),
            app(AiGate::class),
            app(PromptBuilder::class),
            app(ModuleQueueGuard::class),
        );
    }

    private function promptAnswerFor(AiEvaluation $evaluation): string
    {
        $jawaban = JawabanTp::query()->findOrFail($evaluation->jawaban_id);

        return (string) $jawaban->jawaban;
    }

    /**
     * @return array<string, mixed>
     */
    private function chatCompletion(): array
    {
        return [
            'id' => 'chatcmpl-queue-1',
            'object' => 'chat.completion',
            'model' => self::CHAT_MODEL,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => (string) json_encode([
                            'verdict' => 'sesuai',
                            'score' => 90,
                            'confidence' => 0.9,
                            'alasan' => 'Sesuai kunci.',
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ];
    }
}
