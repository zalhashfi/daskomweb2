<?php

namespace Tests\Feature;

use App\Jobs\EvaluateAnswer;
use App\Models\AiConfig;
use App\Models\AiEvaluation;
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
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Lifecycle gate.
 *
 *  1. Gate mati + 5 submission tersubmit (5 baris `queued`): menjalankan job
 *     tidak mengirim satu request pun (Http::assertNothingSent()) dan tiap
 *     baris menjadi `failed` / `AI_DISABLED`.
 *  2. Antrean dikosongkan, gate dihidupkan lewat POST /api-v1/ai-gate/toggle
 *     (aturannya: ON ditolak selama masih ada baris non-terminal) dan
 *     submission baru 5 baris `queued` ter-dispatch; tiap job berhasil `done`.
 */
class AiGateLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://gateway.test';

    private const CHAT_MODEL = 'google/gemini-flash';

    private const ENDPOINT = '/api-v1/jawaban-tp';

    /**
     * Second provider host, used only by the submit that creates the rows
     * which are left waiting for a (turned off) gate.
     */
    private const SUBMIT_BASE_URL = 'https://submit-gateway.test';

    private Modul $modul;

    private AiConfig $config;

    /**
     * Whether the queued evaluations should be left untouched after a
     * submission (the sync queue would otherwise run them immediately).
     */
    private bool $holdDispatchedEvaluations = false;

    /**
     * Provider host the next submit pulls its queued rows from (null: the
     * regular faked gateway).
     */
    private ?string $submitBaseUrl = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.openai_compatible.base_url' => self::BASE_URL,
            'ai.openai_compatible.api_key' => 'sk-test-secret-lifecycle',
            'ai.openai_compatible.chat_model' => self::CHAT_MODEL,
            'ai.openai_compatible.embed_model' => 'google/gemini-embedding-001',
        ]);

        $this->modul = $this->modulWithApprovedKeys(5);

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

    public function test_gate_off_fails_every_queued_row_without_a_request_then_gate_on_dispatches_and_completes(): void
    {
        // 1. Gate mati + 5 submission: the rows are created while the gate is
        // on and then wait for it; no job runs until we run them ourselves.
        $this->holdDispatchedEvaluations = true;
        $this->submitBaseUrl = self::SUBMIT_BASE_URL;

        Http::fake([
            self::SUBMIT_BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion($this->verdictJson('sesuai', 95)),
                200,
            ),
        ]);

        $first = $this->submitFive();
        $rows = $this->evaluationsOf($first);
        $this->assertCount(5, $rows);
        $this->assertSame(5, $rows->where('status', AiEvaluation::STATUS_QUEUED)->count());

        // Gate dimatikan, lalu baris-baris tersebut dijalankan.
        $this->gateOff();

        $active = AiConfig::active();
        $this->assertFalse($active->ai_enabled);

        // The toggle writes a config revision; the waiting rows are evaluated
        // against the snapshot the gate is now operating on.
        AiEvaluation::query()->whereIn('id', $rows->pluck('id'))->update(['ai_config_id' => (int) $active->getKey()]);

        $this->assertSame(5, AiEvaluation::query()->where('status', AiEvaluation::STATUS_QUEUED)->count());

        // Fresh recorder: everything asserted from here on is outbound
        // traffic of the gate-off phase only.
        Http::swap(new Factory);

        foreach ($rows as $row) {
            $this->runJob($row);
        }

        Http::assertNothingSent();

        foreach ($rows as $row) {
            $fresh = $row->fresh();
            $this->assertSame(AiEvaluation::STATUS_FAILED, $fresh->status);
            $this->assertSame('AI_DISABLED', $fresh->error_code);
            $this->assertNotNull($fresh->finished_at);
            $this->assertCount(0, $fresh->attempts()->get(), 'Tidak boleh ada attempt/provider request.');
        }

        // 2. Antrean dikosongkan dulu, baru gate dihidupkan.
        $this->assertSame(
            0,
            AiEvaluation::query()->whereIn('status', ['queued', 'running', 'pending', 'processing'])->count(),
            'Antrean harus kosong sebelum gate dinyalakan.'
        );

        $this->gateOn();

        $this->assertTrue(AiConfig::active()->ai_enabled);

        // 3. Submission baru: 5 baris queued ter-dispatch.
        $this->submitBaseUrl = null;
        $this->holdDispatchedEvaluations = false;

        Queue::fake();
        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion($this->verdictJson('sesuai', 95)),
                200,
            ),
        ]);

        $second = $this->submitFive();

        Queue::assertPushed(EvaluateAnswer::class, 5);

        $newRows = $this->evaluationsOf($second);
        $this->assertCount(5, $newRows);
        $this->assertSame(5, $newRows->where('status', AiEvaluation::STATUS_QUEUED)->count());

        $this->assertSame(
            $newRows->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            collect(Queue::pushed(EvaluateAnswer::class))
                ->map(fn (EvaluateAnswer $job): int => (int) $job->evaluation->getKey())
                ->sort()
                ->values()
                ->all(),
            'Satu job per baris queued.'
        );

        foreach ($newRows as $row) {
            $this->runJob($row);
        }

        foreach ($newRows as $row) {
            $fresh = $row->fresh();
            $this->assertSame(AiEvaluation::STATUS_DONE, $fresh->status);
            $this->assertNull($fresh->error_code);
            $this->assertSame('sesuai', $fresh->verdict);
            $this->assertNotNull($fresh->finished_at);
        }

        $this->assertSame(5, Http::recorded()->count(), 'Hanya 5 baris baru yang boleh mengirim request.');

        // Baris lama tetap failed/AI_DISABLED dan tidak pernah dinegara ulang.
        $this->assertSame(
            5,
            AiEvaluation::query()->whereIn('id', $rows->pluck('id'))->where('error_code', 'AI_DISABLED')->count()
        );
    }

    // ---------------------------------------------------------------------
    // Gate control
    // ---------------------------------------------------------------------

    private function gateOff(): void
    {
        $this->toggleThroughEndpoint(false, 'Pausing the gate for the lifecycle test.');
    }

    private function gateOn(): void
    {
        $this->toggleThroughEndpoint(true, 'Resuming once the queue is drained.');
    }

    /**
     * Real operator path: DDC turns the gate on/off via
     * POST /api-v1/ai-gate/toggle.
     */
    private function toggleThroughEndpoint(bool $enabled, string $reason): void
    {
        $this->actingAs($this->ddc(), 'asisten');

        $response = $this->postJson('/api-v1/ai-gate/toggle', [
            'ai_enabled' => $enabled,
            'reason' => $reason,
        ]);

        $response->assertOk()->assertJsonPath('data.ai_enabled', $enabled);

        Auth::guard('asisten')->logout();
    }

    private function ddc(): Asisten
    {
        $role = Role::firstOrCreate(['name' => 'DDC', 'guard_name' => 'asisten']);
        $role->givePermissionTo(Permission::firstOrCreate([
            'name' => 'manage-ai-gate',
            'guard_name' => 'asisten',
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $assistant = Asisten::factory()->create(['role_id' => $role->id]);
        $assistant->assignRole($role);

        return $assistant;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Modul with five TP questions, each with an approved standard answer.
     */
    private function modulWithApprovedKeys(int $count): Modul
    {
        $modul = Modul::factory()->create();

        $versionSet = SoalVersionSet::query()->create([
            'modul_id' => $modul->id,
            'version_no' => 1,
            'status' => SoalVersionSet::STATUS_APPROVED,
            'bobot_total' => 100,
            'activated_at' => now(),
        ]);

        for ($index = 0; $index < $count; $index++) {
            $soal = SoalTp::query()->create([
                'modul_id' => $modul->id,
                'soal' => 'Soal lifecycle '.$index.'.',
            ]);

            SoalVersionItem::query()->create([
                'version_set_id' => $versionSet->id,
                'soal_type' => SoalVersionItem::SOAL_TP,
                'soal_id' => $soal->id,
                'teks_snapshot' => $soal->soal,
                'bobot' => 100,
                'max_score' => 100,
                'jawaban_standar' => 'Kunci jawaban lifecycle '.$index.'.',
                'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
            ]);
        }

        return $modul;
    }

    /**
     * Five answers over the real submit endpoint (chokepoint + dispatch).
     */
    private function submitFive(): JawabanSubmission
    {
        // The client is built from config/ai.php for every job, so the
        // stand-in host is installed right before this submit; each phase of
        // the lifecycle test installs its own host and its own Http fake.
        config(['ai.openai_compatible.base_url' => $this->submitBaseUrl ?? self::BASE_URL]);

        $praktikan = Praktikan::factory()->create();

        $role = Role::firstOrCreate(['name' => 'lifecycle-praktikan', 'guard_name' => 'praktikan']);
        $role->givePermissionTo(Permission::firstOrCreate([
            'name' => 'praktikum-lms',
            'guard_name' => 'praktikan',
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $praktikan->assignRole($role);

        $this->actingAs($praktikan, 'praktikan');

        $payload = SoalTp::query()
            ->where('modul_id', $this->modul->id)
            ->orderBy('id')
            ->get(['id'])
            ->map(fn (SoalTp $soal): array => [
                'praktikan_id' => $praktikan->id,
                'modul_id' => $this->modul->id,
                'soal_id' => (int) $soal->id,
                'jawaban' => 'Jawaban '.$soal->id.'.',
            ])
            ->all();

        $response = $this->postJson(self::ENDPOINT, $payload);

        $response->assertCreated();

        $submission = JawabanSubmission::query()->findOrFail($response->json('submission_id'));

        if ($this->holdDispatchedEvaluations) {
            // The sync queue already ran the dispatched jobs; reset their
            // answers to the state a real worker would read: fresh `queued`
            // rows, no attempt log.
            $evaluations = AiEvaluation::query()
                ->where('jawaban_type', JawabanSubmission::TIPE_TP)
                ->whereIn('jawaban_id', JawabanTp::query()->where('submission_id', $submission->id)->pluck('id')->all())
                ->get();

            foreach ($evaluations as $evaluation) {
                $evaluation->attempts()->delete();

                $evaluation->forceFill([
                    'status' => AiEvaluation::STATUS_QUEUED,
                    'error_code' => null,
                    'verdict' => null,
                    'alasan' => null,
                    'ai_score' => null,
                    'finished_at' => null,
                    'tokens_in' => 0,
                    'tokens_out' => 0,
                    'latency_ms' => 0,
                    'raw_response' => null,
                    'release_count' => 0,
                ])->save();
            }
        }

        Auth::guard('praktikan')->logout();

        return $submission;
    }

    /**
     * @return \Illuminate\Support\Collection<int, AiEvaluation>
     */
    private function evaluationsOf(JawabanSubmission $submission)
    {
        return AiEvaluation::query()
            ->where('jawaban_type', JawabanSubmission::TIPE_TP)
            ->whereIn('jawaban_id', JawabanTp::query()->where('submission_id', $submission->id)->pluck('id')->all())
            ->orderBy('id')
            ->get();
    }

    private function runJob(AiEvaluation $evaluation): void
    {
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
            'alasan' => 'Sesuai dengan kunci jawaban.',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    private function chatCompletion(string $content): array
    {
        return [
            'id' => 'chatcmpl-lifecycle-1',
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
