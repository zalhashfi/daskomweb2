<?php

namespace Tests\Feature;

use App\Jobs\EvaluateAnswer;
use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\JawabanSubmission;
use App\Models\JawabanTp;
use App\Models\Modul;
use App\Models\ModulDocument;
use App\Models\ModulDocumentChunk;
use App\Models\Praktikan;
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
 * T26c — EvaluateAnswer uses the Retriever when `retrieval_top_k > 0`.
 *
 * - with an active corpus: the answer is embedded (one extra provider call,
 *   recorded as an attempt), the top-k chunks are fenced into
 *   `<konteks_modul>` blocks and their ids stored as `cited_chunk_ids`;
 * - without any active document: no embedding and no chat request at all,
 *   the row is `manual_required` with `alasan` naming the missing corpus;
 * - `retrieval_top_k = 0` keeps the pre-RAG prompt and makes no embedding;
 * - score clamping is unaffected by retrieval.
 *
 * Assertions observe DB state and outbound traffic only.
 */
class EvaluateAnswerRagTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://gateway.test';

    private const CHAT_MODEL = 'google/gemini-flash';

    private const EMBED_MODEL = 'google/gemini-embedding-001';

    /**
     * A query vector that is identical to `CHUNK_VECTOR`, so a chunk indexed
     * with it scores cosine 1.0 against the answer embedding.
     *
     * @var list<float>
     */
    private const QUERY_VECTOR = [1.0, 0.0];

    /**
     * @var list<float>
     */
    private const CHUNK_VECTOR = [1.0, 0.0];

    /**
     * @var list<float>
     */
    private const FAR_VECTOR = [0.0, 1.0];

    private Modul $modul;

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
            'ai.openai_compatible.api_key' => 'sk-test-secret-rag',
            'ai.openai_compatible.chat_model' => self::CHAT_MODEL,
            'ai.openai_compatible.embed_model' => self::EMBED_MODEL,
        ]);

        $this->modul = Modul::factory()->create();
        $praktikan = Praktikan::factory()->create();

        $this->soal = SoalTp::factory()->create([
            'modul_id' => $this->modul->id,
            'soal' => 'Jelaskan fungsi resistor pada rangkaian seri.',
        ]);

        $this->jawaban = JawabanTp::query()->create([
            'praktikan_id' => $praktikan->id,
            'soal_id' => $this->soal->id,
            'modul_id' => $this->modul->id,
            'submission_id' => $this->submission($praktikan)->id,
            'jawaban' => 'Resistor membatasi arus listrik pada rangkaian.',
        ]);

        $this->versionSet = SoalVersionSet::create([
            'modul_id' => $this->modul->id,
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
            'embed_driver' => 'openai_compatible',
            'embed_model_name' => self::EMBED_MODEL,
            'embedding_dimension' => 2,
            'retrieval_top_k' => 4,
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
    // With a corpus
    // ---------------------------------------------------------------------

    public function test_two_active_chunks_are_put_in_the_prompt_and_cited(): void
    {
        $document = $this->activeDocument();

        $first = $this->chunk($document, 0, 'Modul bab 1: resistor membatasi arus listrik.', self::CHUNK_VECTOR);
        $second = $this->chunk($document, 1, 'Modul bab 2: resistor membagi tegangan pada rangkaian seri.', self::FAR_VECTOR);

        [$scenario, $evaluation] = $this->fakeGateway();

        $this->runJob($evaluation);

        $fresh = $evaluation->fresh();
        $this->assertSame(AiEvaluation::STATUS_DONE, $fresh->status);
        $this->assertSame('sesuai', $fresh->verdict);

        $prompt = $this->sentPrompt($scenario);
        $this->assertStringContainsString('<konteks_modul>', $prompt);
        $this->assertStringContainsString('Modul bab 1: resistor membatasi arus listrik.', $prompt);
        $this->assertStringContainsString('Modul bab 2: resistor membagi tegangan pada rangkaian seri.', $prompt);
        $this->assertStringContainsString('konteks modul', strtolower($prompt));

        $this->assertSame([$first->id, $second->id], $fresh->cited_chunk_ids);

        // One embedding request, then one chat request.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE_URL.'/v1/embeddings'
            && $request['input'][0] !== '');
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE_URL.'/v1/chat/completions');

        // The extra embedding call is accounted for in the attempt log.
        $this->assertSame(2, $fresh->attempts()->count());
        $this->assertDatabaseHas('ai_evaluation_attempts', [
            'ai_evaluation_id' => $evaluation->getKey(),
            'outcome' => 'done',
            'error_code' => EvaluateAnswer::ERROR_EMBED_QUERY,
        ]);
    }

    public function test_top_k_limits_the_number_of_cited_chunks(): void
    {
        $this->config->forceFill(['retrieval_top_k' => 1])->save();

        $document = $this->activeDocument();
        $best = $this->chunk($document, 0, 'Chunk paling relevan.', self::CHUNK_VECTOR);
        $this->chunk($document, 1, 'Chunk kurang relevan.', self::FAR_VECTOR);

        [$scenario, $evaluation] = $this->fakeGateway();

        $this->runJob($evaluation);

        $this->assertSame([$best->id], $evaluation->fresh()->cited_chunk_ids);
        $this->assertStringContainsString('Chunk paling relevan.', $this->sentPrompt($scenario));
        $this->assertStringNotContainsString('Chunk kurang relevan.', $this->sentPrompt($scenario));
    }

    public function test_context_budget_drops_lowest_ranked_chunks(): void
    {
        $document = $this->activeDocument();
        $best = $this->chunk($document, 0, 'Penting: resistor membatasi arus.', self::CHUNK_VECTOR);
        $this->chunk($document, 1, 'Kurang relevan. '.str_repeat('p', 600), self::FAR_VECTOR);

        // Room for the scaffolding + the best chunk (~553 tokens), not for both (~715).
        $this->config->forceFill(['max_context_tokens' => 620])->save();

        [$scenario, $evaluation] = $this->fakeGateway();

        $this->runJob($evaluation);

        $fresh = $evaluation->fresh();
        $this->assertSame(AiEvaluation::STATUS_DONE, $fresh->status);
        $this->assertSame([$best->id], $fresh->cited_chunk_ids);
        $this->assertStringNotContainsString('Kurang relevan.', $this->sentPrompt($scenario));
    }

    public function test_score_is_still_clamped_with_chunks(): void
    {
        $document = $this->activeDocument();
        $this->chunk($document, 0, 'Chunk relevan.', self::CHUNK_VECTOR);
        $this->chunk($document, 1, 'Chunk lain.', self::FAR_VECTOR);

        [, $evaluation] = $this->fakeGateway(score: 150);

        $this->runJob($evaluation);

        $fresh = $evaluation->fresh();
        $this->assertSame(AiEvaluation::STATUS_DONE, $fresh->status);
        $this->assertSame('100.00', $fresh->ai_score);
        $this->assertSame(100, $fresh->max_score);
    }

    public function test_negative_score_is_clamped_with_chunks(): void
    {
        $document = $this->activeDocument();
        $this->chunk($document, 0, 'Chunk relevan.', self::CHUNK_VECTOR);

        [, $evaluation] = $this->fakeGateway(verdict: 'tidak_sesuai', score: -25);

        $this->runJob($evaluation);

        $this->assertSame('0.00', $evaluation->fresh()->ai_score);
    }

    // ---------------------------------------------------------------------
    // Without a corpus
    // ---------------------------------------------------------------------

    public function test_modul_without_active_document_never_embeds(): void
    {
        Http::fake();

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $fresh = $evaluation->fresh();
        $this->assertSame(AiEvaluation::STATUS_MANUAL_REQUIRED, $fresh->status);
        $this->assertStringContainsString('korpus modul belum tersedia', strtolower((string) $fresh->alasan));
        $this->assertSame([], $fresh->cited_chunk_ids);
        Http::assertNothingSent();
        $this->assertSame(0, $fresh->attempts()->count());
    }

    public function test_inactive_document_counts_as_missing_corpus(): void
    {
        Http::fake();

        $document = $this->activeDocument(['status' => ModulDocument::STATUS_APPROVED]);
        $this->chunk($document, 0, 'Belum disetujui.', self::CHUNK_VECTOR);

        $evaluation = $this->queuedEvaluation();

        $this->runJob($evaluation);

        $fresh = $evaluation->fresh();
        $this->assertSame(AiEvaluation::STATUS_MANUAL_REQUIRED, $fresh->status);
        $this->assertStringContainsString('korpus modul belum tersedia', strtolower((string) $fresh->alasan));
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // retrieval_top_k = 0
    // ---------------------------------------------------------------------

    public function test_retrieval_disabled_makes_no_embedding_call(): void
    {
        $this->config->forceFill(['retrieval_top_k' => 0])->save();

        $document = $this->activeDocument();
        $this->chunk($document, 0, 'Chunk yang tidak boleh dipakai.', self::CHUNK_VECTOR);

        [$scenario, $evaluation] = $this->fakeGateway();

        $this->runJob($evaluation);

        $fresh = $evaluation->fresh();
        $this->assertSame(AiEvaluation::STATUS_DONE, $fresh->status);
        $this->assertSame([], $fresh->cited_chunk_ids);

        $prompt = $this->sentPrompt($scenario);
        $this->assertStringNotContainsString('Chunk yang tidak boleh dipakai.', $prompt);
        $this->assertSame(1, $fresh->attempts()->count());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/v1/embeddings'));
        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function submission(Praktikan $praktikan): JawabanSubmission
    {
        return JawabanSubmission::create([
            'praktikan_id' => $praktikan->id,
            'modul_id' => $this->modul->id,
            'tipe' => 'tp',
            'attempt_no' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeDocument(array $attributes = []): ModulDocument
    {
        return ModulDocument::create(array_merge([
            'modul_id' => $this->modul->id,
            'original_name' => 'modul.pdf',
            'path' => 'modul-documents/modul.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'status' => ModulDocument::STATUS_ACTIVE,
            'index_version' => 1,
        ], $attributes));
    }

    /**
     * @param  list<float>  $embedding
     */
    private function chunk(ModulDocument $document, int $chunkIndex, string $content, array $embedding): ModulDocumentChunk
    {
        return ModulDocumentChunk::create([
            'modul_document_id' => $document->id,
            'index_version' => 1,
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'embedding' => $embedding,
            'embedding_dimension' => count($embedding),
        ]);
    }

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
     * Fake both endpoints and return the scenario holder (which captures the
     * chat prompt) plus a queued evaluation.
     *
     * @return array{0: \stdClass, 1: AiEvaluation}
     */
    private function fakeGateway(string $verdict = 'sesuai', int|float $score = 90): array
    {
        $scenario = new \stdClass;
        $scenario->prompt = null;

        Http::fake([
            self::BASE_URL.'/v1/embeddings' => function (Request $request) {
                $inputs = (array) ($request['input'] ?? []);

                return Http::response([
                    'object' => 'list',
                    'data' => array_map(static fn (int $index): array => [
                        'object' => 'embedding',
                        'index' => $index,
                        'embedding' => self::QUERY_VECTOR,
                    ], array_keys($inputs)),
                    'model' => self::EMBED_MODEL,
                ], 200);
            },
            self::BASE_URL.'/v1/chat/completions' => function (Request $request) use ($scenario, $verdict, $score) {
                $scenario->prompt = (string) ($request->data()['messages'][1]['content'] ?? '');

                return Http::response([
                    'id' => 'chatcmpl-rag-1',
                    'object' => 'chat.completion',
                    'model' => self::CHAT_MODEL,
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => (string) json_encode([
                                    'verdict' => $verdict,
                                    'score' => $score,
                                    'confidence' => 0.8,
                                    'alasan' => 'Dinilai terhadap kunci jawaban.',
                                ], JSON_UNESCAPED_UNICODE),
                            ],
                            'finish_reason' => 'stop',
                        ],
                    ],
                ], 200);
            },
        ]);

        return [$scenario, $this->queuedEvaluation()];
    }

    private function sentPrompt(\stdClass $scenario): string
    {
        $this->assertIsString($scenario->prompt, 'Prompt chat harus terkirim ke provider.');

        return $scenario->prompt;
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
}
