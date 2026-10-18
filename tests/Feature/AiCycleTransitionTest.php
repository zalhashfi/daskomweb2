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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Siklus konfigurasi tiga modul.
 *
 *  - modul 1 dinilai dengan config A: satu baris `ai_evaluations` yang
 *    menunjuk `ai_config_id` = A dan selesai `done`;
 *  - config B diaktifkan sebagai revisi AiConfig baru (A deaktif, B aktif);
 *  - evaluasi milik modul 1 tetap menunjuk A (`ai_config_id` tidak berubah)
 *    dan respons endpoint modul 1 tidak berubah;
 *  - submission modul 2 sesudahnya memakai B (`ai_config_id` = B), bukan A.
 */
class AiCycleTransitionTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://gateway.test';

    private const CHAT_MODEL_A = 'google/gemini-flash';

    private const CHAT_MODEL_B = 'google/gemini-flash-next';

    private const ENDPOINT = '/api-v1/jawaban-tp';

    private Modul $modulOne;

    private Modul $modulTwo;

    private AiConfig $configA;

    private AiEvaluation $evaluationA;

    private Praktikan $praktikanModulOne;

    protected function setUp(): void
    {
        parent::setUp();

        Auth::guard('web')->logout();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config([
            'ai.openai_compatible.base_url' => self::BASE_URL,
            'ai.openai_compatible.api_key' => 'sk-test-secret-cycle',
            'ai.openai_compatible.chat_model' => self::CHAT_MODEL_A,
            'ai.openai_compatible.embed_model' => 'google/gemini-embedding-001',
            'ai.openai_compatible.chat_model_version' => 'gemini-flash-2026-10',
        ]);
    }

    // ---------------------------------------------------------------------
    // Acceptance
    // ---------------------------------------------------------------------

    public function test_module_one_keeps_config_a_after_config_b_becomes_active(): void
    {
        $this->gradeModulOneUnderConfigA();

        $configAId = (int) $this->configA->getKey();
        $evaluationId = (int) $this->evaluationA->getKey();

        $this->assertSame($configAId, (int) $this->evaluationA->ai_config_id);
        $this->assertSame(AiEvaluation::STATUS_DONE, $this->evaluationA->status);

        $responseBefore = $this->moduleOneResponse();

        $configB = $this->activateConfigB();

        $configBId = (int) $configB->getKey();

        $this->assertNotSame($configAId, $configBId, 'Config B harus baris baru, bukan baris A yang diedit.');
        $this->assertSame($configBId, (int) AiConfig::active()->getKey());
        $this->assertFalse((bool) AiConfig::query()->findOrFail($configAId)->is_active);

        // Modul 1 masih menunjuk config A.
        $evaluationAfter = AiEvaluation::query()->findOrFail($evaluationId);

        $this->assertSame($configAId, (int) $evaluationAfter->ai_config_id, 'ai_config_id modul 1 tidak boleh berubah.');
        $this->assertNotSame($configBId, (int) $evaluationAfter->ai_config_id);
        $this->assertSame(AiEvaluation::STATUS_DONE, $evaluationAfter->status);

        // Respons endpoint modul 1 identik sebelum dan sesudah config B aktif.
        $this->assertSame($responseBefore, $this->moduleOneResponse());

        // Submission modul 2 memakai config B.
        $evaluationB = $this->submitModulTwoUnderConfigB();

        $this->assertSame($configBId, (int) $evaluationB->ai_config_id, 'Modul 2 harus memakai config B.');
        $this->assertNotSame($configAId, (int) $evaluationB->ai_config_id);

        // Dan modul 1 tetap pada A setelah modul 2 menulis baris barunya.
        $this->assertSame($configAId, (int) AiEvaluation::query()->findOrFail($evaluationId)->ai_config_id);
        $this->assertSame($responseBefore, $this->moduleOneResponse());
    }

    // ---------------------------------------------------------------------
    // Steps
    // ---------------------------------------------------------------------

    /**
     * Modul 1: config A aktif, submit lewat chokepoint, job menilai sampai
     * `done`.
     */
    private function gradeModulOneUnderConfigA(): void
    {
        Queue::fake();

        $this->configA = $this->createActiveConfig(self::CHAT_MODEL_A);
        $this->modulOne = $this->modulWithApprovedKey(1);

        $this->assertSame((int) $this->configA->getKey(), (int) AiConfig::active()->getKey());

        Http::fake([
            self::BASE_URL.'/v1/chat/completions' => Http::response(
                $this->chatCompletion($this->verdictJson('sesuai', 90)),
                200,
            ),
        ]);

        $this->praktikanModulOne = $this->actingAsPraktikan();

        $submission = $this->submit($this->praktikanModulOne, $this->modulOne);

        $this->evaluationA = AiEvaluation::query()->sole();

        $this->assertSame((int) $this->configA->getKey(), (int) $this->evaluationA->ai_config_id);
        $this->assertSame(AiEvaluation::STATUS_QUEUED, $this->evaluationA->status);

        (new EvaluateAnswer($this->evaluationA))->handle(
            app(AiClientManager::class),
            app(AiGate::class),
            app(PromptBuilder::class),
        );

        $this->evaluationA = $this->evaluationA->fresh();

        $this->assertSame(AiEvaluation::STATUS_DONE, $this->evaluationA->status);
        $this->assertSame('sesuai', $this->evaluationA->verdict);
        $this->assertSame((int) $this->configA->getKey(), (int) $this->evaluationA->ai_config_id);

        $firstResponse = $this->moduleOneResponse();

        $this->assertSame((int) $this->modulOne->soal_id, $firstResponse['soal_id']);
        $this->assertSame([
            'status' => AiEvaluation::STATUS_DONE,
            'verdict' => 'sesuai',
            'alasan' => 'Sesuai dengan kunci jawaban.',
            'max_score' => 100,
        ], $firstResponse['ai']);

        Auth::guard('praktikan')->logout();
    }

    /**
     * Config B dikelola lewat model yang sama dengan seeder/operator: baris A
     * deaktif, baris B aktif (revisi baru), bukan UPDATE pada baris A.
     */
    private function activateConfigB(): AiConfig
    {
        $source = AiConfig::query()->findOrFail($this->configA->getKey());
        $source->is_active = false;
        $source->save();

        // A deactivated row is never reactivated in place: the new model is
        // written onto a fresh instance and persisted as the new snapshot.
        $revision = new AiConfig;
        $revision->setRawAttributes(array_merge(
            array_diff_key($source->getAttributes(), array_flip(['id', 'created_at', 'updated_at'])),
            ['chat_model' => self::CHAT_MODEL_B, 'is_active' => true],
        ), true);
        $revision->save();

        $this->assertSame(2, AiConfig::query()->count());
        $this->assertSame(1, AiConfig::query()->where('is_active', true)->count());

        $configB = AiConfig::active();

        $this->assertSame(self::CHAT_MODEL_B, $configB->chat_model);
        $this->assertSame(self::CHAT_MODEL_A, AiConfig::query()->findOrFail($this->configA->getKey())->chat_model);

        return $configB;
    }

    /**
     * Modul 2: submission lewat jalur yang sama dengan modul 1, setelah
     * config B aktif.
     */
    private function submitModulTwoUnderConfigB(): AiEvaluation
    {
        Queue::fake();

        $this->modulTwo = $this->modulWithApprovedKey(2);

        $praktikan = $this->actingAsPraktikan();

        $submission = $this->submit($praktikan, $this->modulTwo);

        Auth::guard('praktikan')->logout();

        return AiEvaluation::query()
            ->whereIn('jawaban_id', JawabanTp::query()->where('submission_id', $submission->id)->pluck('id')->all())
            ->sole();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function createActiveConfig(string $chatModel): AiConfig
    {
        return AiConfig::query()->create([
            'driver' => 'openai_compatible',
            'base_url' => self::BASE_URL,
            'chat_model' => $chatModel,
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

    /**
     * Modul with one TP question bound to an approved standard answer; the
     * soal id is kept on the model for the endpoint assertion.
     */
    private function modulWithApprovedKey(int $index): Modul
    {
        $modul = Modul::factory()->create(['judul' => 'Modul '.$index]);

        $versionSet = SoalVersionSet::query()->create([
            'modul_id' => $modul->id,
            'version_no' => 1,
            'status' => SoalVersionSet::STATUS_APPROVED,
            'bobot_total' => 100,
            'activated_at' => now(),
        ]);

        $soal = SoalTp::query()->create([
            'modul_id' => $modul->id,
            'soal' => 'Soal modul '.$index.'.',
        ]);

        SoalVersionItem::query()->create([
            'version_set_id' => $versionSet->id,
            'soal_type' => SoalVersionItem::SOAL_TP,
            'soal_id' => $soal->id,
            'teks_snapshot' => $soal->soal,
            'bobot' => 100,
            'max_score' => 100,
            'jawaban_standar' => 'Kunci jawaban modul '.$index.'.',
            'jawaban_standar_status' => SoalVersionItem::STATUS_APPROVED,
        ]);

        $modul->setAttribute('soal_id', (int) $soal->id);

        return $modul;
    }

    private function submit(Praktikan $praktikan, Modul $modul): JawabanSubmission
    {
        $response = $this->postJson(self::ENDPOINT, [
            [
                'praktikan_id' => $praktikan->id,
                'modul_id' => $modul->id,
                'soal_id' => (int) $modul->soal_id,
                'jawaban' => 'Jawaban praktikan untuk '.$modul->judul.'.',
            ],
        ]);

        $response->assertCreated();

        return JawabanSubmission::query()->findOrFail($response->json('submission_id'));
    }

    /**
     * The answer row of modul 1 as the assistant endpoint returns it.
     *
     * @return array<string, mixed>
     */
    private function moduleOneResponse(): array
    {
        $this->actingAsAssistant();

        $response = $this->getJson(
            '/api-v1/jawaban-tp/'.$this->praktikanModulOne->nim.'/'.$this->modulOne->id
        );

        $response->assertOk()->assertJsonPath('success', true);

        Auth::guard('asisten')->logout();

        return $response->json('data.jawabanData.0');
    }

    private function actingAsPraktikan(): Praktikan
    {
        $praktikan = Praktikan::factory()->create();

        $role = Role::firstOrCreate(['name' => 'ai-cycle-praktikan', 'guard_name' => 'praktikan']);
        $role->givePermissionTo(Permission::firstOrCreate([
            'name' => 'praktikum-lms',
            'guard_name' => 'praktikan',
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $praktikan->assignRole($role);

        $this->actingAs($praktikan, 'praktikan');

        return $praktikan;
    }

    private function actingAsAssistant(): Asisten
    {
        $role = Role::firstOrCreate(['name' => 'ai-cycle-asisten', 'guard_name' => 'asisten']);
        $role->givePermissionTo(Permission::firstOrCreate([
            'name' => 'nilai-praktikan',
            'guard_name' => 'asisten',
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $assistant = Asisten::factory()->create(['role_id' => $role->id]);
        $assistant->assignRole($role);

        $this->actingAs($assistant, 'asisten');

        return $assistant;
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
            'id' => 'chatcmpl-cycle-1',
            'object' => 'chat.completion',
            'model' => self::CHAT_MODEL_A,
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
