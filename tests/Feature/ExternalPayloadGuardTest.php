<?php

namespace Tests\Feature;

use App\Exceptions\AiUnavailableException;
use App\Exceptions\ExternalPayloadDisabledException;
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
use App\Services\Ai\PromptBuilder;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeChatClient;
use Tests\Support\FakeChatClientManager;
use Tests\TestCase;
use Throwable;

/**
 * T20d — external payload guard on the job path + release for 429/503.
 *
 * The guard is a whitelist: `ai_enabled`, `allow_external_payload` (true or
 * 1 only — null/0/'false' are rejected), a verified provider policy and a
 * chat driver from EvaluateAnswer::EXTERNAL_DRIVERS. A rejection never
 * reaches the network. 429/503 are a long `release()` honouring
 * `retry-after` (max 10, not using `tries`), never a fallback provider.
 */
class ExternalPayloadGuardTest extends TestCase
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
            'ai.openai_compatible.api_key' => 'sk-test-secret-guard',
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

        $this->config = $this->createConfig($this->configAttributes());
    }

    // ---------------------------------------------------------------------
    // Whitelist
    // ---------------------------------------------------------------------

    public function test_external_driver_with_payload_disabled_fails_without_request(): void
    {
        Http::fake();

        $this->config = $this->createConfig(array_merge($this->configAttributes(), [
            'allow_external_payload' => false,
        ]));

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'failed',
            'error_code' => 'EXTERNAL_PAYLOAD_DISABLED',
        ]);

        $this->assertNotNull($evaluation->fresh()->finished_at);
        Http::assertNothingSent();
    }

    public function test_missing_payload_column_on_a_raw_row_fails_closed(): void
    {
        Http::fake();

        // Raw insert (not the seeder/model) that never names
        // allow_external_payload: the schema default leaves it at 0, i.e.
        // "falsey", and the guard must fail closed.
        $this->config = $this->rawConfig($this->configAttributes(omit: ['allow_external_payload']));

        $this->assertSame(
            0,
            (int) DB::table('ai_configs')->where('id', $this->config->id)->value('allow_external_payload')
        );

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'failed',
            'error_code' => 'EXTERNAL_PAYLOAD_DISABLED',
        ]);

        Http::assertNothingSent();
    }

    public function test_null_payload_value_on_a_raw_row_fails_closed(): void
    {
        Http::fake();

        // Raw insert passing NULL for the column (MySQL non-strict mode would
        // store 0, SQLite would store NULL): both must be rejected.
        $attributes = $this->configAttributes();
        $attributes['allow_external_payload'] = null;

        $rejectedBySchema = false;

        try {
            DB::table('ai_configs')->insert(array_merge($attributes, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        } catch (QueryException) {
            // Strict MySQL refuses NULL on a NOT NULL column: the write itself
            // is already closed by the schema, so no row can ever read NULL back.
            $rejectedBySchema = true;
        }

        if ($rejectedBySchema) {
            $this->assertDatabaseMissing('ai_configs', ['driver' => 'openai_compatible', 'chat_model' => 'null-row']);
            $this->assertTrue(true, 'Skema menolak NULL pada allow_external_payload.');

            return;
        }

        $this->config = AiConfig::query()->orderByDesc('id')->firstOrFail();
        $this->assertNull(DB::table('ai_configs')->where('id', $this->config->id)->value('allow_external_payload'));

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'failed',
            'error_code' => 'EXTERNAL_PAYLOAD_DISABLED',
        ]);

        Http::assertNothingSent();
    }

    public function test_string_false_payload_value_is_rejected(): void
    {
        Http::fake();

        // A row written by an external tool may carry the string 'false'.
        // MySQL strict mode refuses such an insert on a NOT NULL column, so
        // the stored value is modelled exactly like Eloquent hydrates it
        // (newFromBuilder): the policy must read that raw column, never the
        // boolean cast, under which 'false' would be true.
        $snapshot = (new AiConfig)->newFromBuilder(array_merge(
            $this->configAttributes(),
            ['id' => 999, 'allow_external_payload' => 'false'],
        ));

        $this->assertSame('false', $snapshot->getRawOriginal('allow_external_payload'));
        $this->assertTrue($snapshot->allow_external_payload, 'Cast boolean memang true: itulah jebakannya.');

        try {
            $snapshot->assertPayloadPolicy();
        } catch (ExternalPayloadDisabledException $exception) {
            $this->assertSame('EXTERNAL_PAYLOAD_DISABLED', $exception->errorCode());

            return;
        }

        $this->fail("Nilai 'false' pada allow_external_payload harus ditolak.");
    }

    public function test_unverified_provider_policy_fails_without_request(): void
    {
        Http::fake();

        $this->config = $this->createConfig(array_merge($this->configAttributes(), [
            'provider_policy_verified_at' => null,
        ]));

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'failed',
            'error_code' => 'PROVIDER_POLICY_UNVERIFIED',
        ]);

        Http::assertNothingSent();
    }

    public function test_ai_disabled_fails_without_request(): void
    {
        Http::fake();

        $this->config = $this->createConfig(array_merge($this->configAttributes(), [
            'ai_enabled' => false,
        ]));

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'failed',
            'error_code' => 'AI_DISABLED',
        ]);

        Http::assertNothingSent();
    }

    public function test_unknown_driver_is_not_delegated_to_another_provider(): void
    {
        Http::fake();

        $this->config = $this->createConfig(array_merge($this->configAttributes(), [
            'driver' => 'ollama',
        ]));

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'failed',
            'error_code' => 'EXTERNAL_PAYLOAD_DISABLED',
        ]);

        Http::assertNothingSent();
    }

    public function test_whitelisted_driver_sends_request_and_records_driver(): void
    {
        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion($this->verdictJson('sesuai', 90)),
                200
            ),
        ]);

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $fresh = $evaluation->fresh();
        $this->assertSame(AiEvaluation::STATUS_DONE, $fresh->status);
        $this->assertSame(AiClientManager::DRIVER_OPENAI_COMPATIBLE, $fresh->driver);
        $this->assertNull($fresh->error_code);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::BASE_URL.'/v1/chat/completions');
    }

    // ---------------------------------------------------------------------
    // 429 → release (queue, not a row failure)
    // ---------------------------------------------------------------------

    public function test_repeated_rate_limit_is_released_then_succeeds(): void
    {
        Http::fake();

        $this->assertContains(
            AiUnavailableException::CODE_EXTERNAL_RATE_LIMIT,
            EvaluateAnswer::RELEASE_ERROR_CODES,
            '429 harus menjadi release, bukan kegagalan baris.'
        );

        $client = FakeChatClient::thenSucceed([
            AiUnavailableException::rateLimited(45),
            AiUnavailableException::rateLimited(45),
        ], [
            'content' => $this->verdictJson('sesuai', 85),
            'model' => self::CHAT_MODEL,
        ]);

        $evaluation = $this->queuedEvaluation();
        $failedBefore = AiEvaluation::query()->where('status', 'failed')->count();

        foreach ([45, 45] as $delay) {
            $row = AiEvaluation::query()->findOrFail($evaluation->getKey());
            $queueJob = $this->fakeQueueJob();

            $this->runJob($row, $client, $queueJob);

            $this->assertSame(1, $queueJob->releaseCalls, '429 harus release(), bukan throw.');
            $this->assertSame($delay, $queueJob->releaseDelay, 'Release harus menghormati retry-after.');

            $queued = $evaluation->fresh();
            $this->assertNotSame(AiEvaluation::STATUS_FAILED, $queued->status, '429 tidak boleh menandai baris failed.');
            $this->assertNull($queued->error_code);
            $this->assertNull($queued->finished_at);
        }

        $this->assertSame($failedBefore, AiEvaluation::query()->where('status', 'failed')->count());
        $this->assertSame(2, $evaluation->fresh()->release_count);

        // The 11th release would exceed MAX_RELEASES=10; the third call answers 200.
        $queueJob = $this->fakeQueueJob();
        $this->runJob($evaluation->fresh(), $client, $queueJob);

        $this->assertSame(0, $queueJob->releaseCalls);

        $fresh = $evaluation->fresh();
        $this->assertSame(AiEvaluation::STATUS_DONE, $fresh->status);
        $this->assertSame('85.00', $fresh->ai_score);
        $this->assertSame(3, $client->calls);

        // No fallback: every outbound attempt went to the one configured driver.
        Http::assertNothingSent();
    }

    public function test_rate_limit_without_retry_after_uses_a_bounded_backoff(): void
    {
        Http::fake();

        $client = new FakeChatClient(AiUnavailableException::rateLimited());
        $evaluation = $this->queuedEvaluation();
        $queueJob = $this->fakeQueueJob();

        $this->runJob($evaluation, $client, $queueJob);

        $this->assertSame(1, $queueJob->releaseCalls);
        $this->assertGreaterThan(0, (int) $queueJob->releaseDelay);
        $this->assertLessThanOrEqual(EvaluateAnswer::MAX_RELEASE_DELAY_SECONDS, (int) $queueJob->releaseDelay);
        $this->assertSame('processing', $evaluation->fresh()->status);
    }

    public function test_release_delay_never_exceeds_the_cap(): void
    {
        Http::fake();

        $client = new FakeChatClient(AiUnavailableException::rateLimited(999999));
        $evaluation = $this->queuedEvaluation();
        $queueJob = $this->fakeQueueJob();

        $this->runJob($evaluation, $client, $queueJob);

        $this->assertSame(EvaluateAnswer::MAX_RELEASE_DELAY_SECONDS, $queueJob->releaseDelay);
    }

    public function test_release_budget_is_capped_at_ten(): void
    {
        Http::fake();

        $this->assertSame(10, EvaluateAnswer::MAX_RELEASES);

        $client = new FakeChatClient(AiUnavailableException::unavailable(5));
        $evaluation = $this->queuedEvaluation(['release_count' => EvaluateAnswer::MAX_RELEASES]);
        $queueJob = $this->fakeQueueJob();

        $this->expectJobThrows(
            fn () => $this->runJob($evaluation, $client, $queueJob),
            AiUnavailableException::class,
            'Setelah 10 release, 503 harus menjadi failed/EXTERNAL_UNAVAILABLE.'
        );

        $this->assertSame(0, $queueJob->releaseCalls);
        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'failed',
            'error_code' => 'EXTERNAL_UNAVAILABLE',
        ]);
    }

    public function test_provider_unavailable_is_released_and_never_falls_back(): void
    {
        Http::fake();

        $this->assertContains(
            AiUnavailableException::CODE_EXTERNAL_UNAVAILABLE,
            EvaluateAnswer::RELEASE_ERROR_CODES
        );

        $client = new FakeChatClient(AiUnavailableException::unavailable(30));
        $evaluation = $this->queuedEvaluation();
        $queueJob = $this->fakeQueueJob();

        $this->runJob($evaluation, $client, $queueJob);

        $this->assertSame(1, $queueJob->releaseCalls);
        $this->assertSame(30, $queueJob->releaseDelay);

        $fresh = $evaluation->fresh();
        $this->assertSame('processing', $fresh->status);
        $this->assertNull($fresh->error_code);
        $this->assertSame(1, $fresh->attempts()->count(), 'Hanya satu percobaan provider.');
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // API failure → failed/EXTERNAL_ERROR, no fallback
    // ---------------------------------------------------------------------

    public function test_api_failure_marks_external_error_without_fallback(): void
    {
        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                ['error' => ['message' => 'upstream exploded']],
                500
            ),
        ]);

        $evaluation = $this->queuedEvaluation();

        $this->expectJobThrows(fn () => $this->runJob($evaluation), AiUnavailableException::class);

        $this->assertDatabaseHas('ai_evaluations', [
            'id' => $evaluation->getKey(),
            'status' => 'failed',
            'error_code' => 'EXTERNAL_ERROR',
        ]);

        $this->assertDatabaseHas('ai_evaluation_attempts', [
            'ai_evaluation_id' => $evaluation->getKey(),
            'attempt_no' => 1,
            'http_status' => 500,
            'error_code' => 'EXTERNAL_ERROR',
        ]);

        // One provider, one request: a failure is surfaced, not re-routed.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::BASE_URL.'/v1/chat/completions');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param  list<string>  $omit  columns left to the schema default
     * @return array<string, mixed>
     */
    private function configAttributes(array $omit = []): array
    {
        return array_diff_key([
            'driver' => 'openai_compatible',
            'base_url' => self::BASE_URL,
            'chat_model' => self::CHAT_MODEL,
            'retrieval_top_k' => 4,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'allow_external_payload' => true,
            'ai_enabled' => true,
            'budget_tokens_per_modul' => 500000,
            'provider_policy_verified_at' => now(),
            'is_active' => true,
        ], array_flip($omit));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createConfig(array $attributes): AiConfig
    {
        return AiConfig::query()->create($attributes);
    }

    /**
     * Insert straight into the table so the column keeps its raw value
     * (null / 'false' / 0), bypassing model casts and defaults.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function rawConfig(array $attributes): AiConfig
    {
        DB::table('ai_configs')->insert(
            array_merge($attributes, [
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );

        return AiConfig::query()->orderByDesc('id')->firstOrFail();
    }

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
     * Run handle() directly with an optional transport double and fake queue
     * job attached (so `release()` can be observed).
     */
    private function runJob(
        AiEvaluation $evaluation,
        ?FakeChatClient $client = null,
        ?QueueJob $queueJob = null,
    ): void {
        $manager = $client === null
            ? app(AiClientManager::class)
            : new FakeChatClientManager($client);

        $job = new EvaluateAnswer($evaluation);

        if ($queueJob !== null) {
            $job->setJob($queueJob);
        }

        $job->handle($manager, app(AiGate::class), app(PromptBuilder::class));
    }

    private function expectJobThrows(callable $callback, string $class, string $message = ''): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->assertInstanceOf($class, $exception, $message);

            return;
        }

        $this->fail($message !== '' ? $message : 'Sebuah exception harus dilempar.');
    }

    /**
     * Minimal queue job that records release()/attempts() instead of touching
     * a real queue driver.
     */
    private function fakeQueueJob(int $attempts = 1): QueueJob
    {
        return new class($attempts) implements QueueJob
        {
            public int $releaseCalls = 0;

            public ?int $releaseDelay = null;

            public function __construct(private int $attempts) {}

            public function uuid(): ?string
            {
                return 'test-uuid';
            }

            public function getRawBody(): string
            {
                return '';
            }

            public function getJobId(): string|int|null
            {
                return '1';
            }

            public function attempts(): int
            {
                return $this->attempts;
            }

            public function delete(): void {}

            public function release($delay = 0): void
            {
                $this->releaseCalls++;
                $this->releaseDelay = (int) $delay;
            }

            public function isDeleted(): bool
            {
                return false;
            }

            public function isReleased(): bool
            {
                return $this->releaseCalls > 0;
            }

            public function hasFailed(): bool
            {
                return false;
            }

            public function markAsFailed(): void {}

            public function fail($e = null): void {}

            public function isDeletedOrReleased(): bool
            {
                return $this->releaseCalls > 0;
            }

            public function payload(): string
            {
                return '{}';
            }

            public function fire(): void {}

            public function maxTries(): ?int
            {
                return null;
            }

            public function maxExceptions(): ?int
            {
                return null;
            }

            public function timeout(): ?int
            {
                return null;
            }

            public function retryUntil(): ?\DateTimeInterface
            {
                return null;
            }

            public function getName(): string
            {
                return 'test';
            }

            public function resolveName(): string
            {
                return 'test';
            }

            public function getConnectionName(): ?string
            {
                return null;
            }

            public function getQueue(): ?string
            {
                return 'default';
            }
        };
    }

    private function verdictJson(string $verdict, int|float $score): string
    {
        return (string) json_encode([
            'verdict' => $verdict,
            'score' => $score,
            'confidence' => 0.9,
            'alasan' => 'Dinilai terhadap kunci jawaban.',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    private function chatCompletion(string $content): array
    {
        return [
            'id' => 'chatcmpl-guard-1',
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
