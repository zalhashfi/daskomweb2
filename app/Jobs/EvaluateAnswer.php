<?php

namespace App\Jobs;

use App\Exceptions\AiUnavailableException;
use App\Models\AiConfig;
use App\Models\AiEvaluation;
use App\Models\JawabanMandiri;
use App\Models\JawabanTp;
use App\Models\SoalVersionItem;
use App\Services\Ai\AiClientManager;
use App\Services\Ai\AiGate;
use App\Services\Ai\PromptBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Grades one practicum answer against its approved standard answer
 * (SPEC 02-01 §5, T21).
 *
 * ROW CONTRACT: the caller (the JawabanSubmissionWriter chokepoint, T23a)
 * already inserted the `ai_evaluations` row; this job only ever reads and
 * updates it. It never inserts, never calls firstOrCreate/insertOrIgnore, so
 * re-dispatching a job cannot duplicate an evaluation.
 *
 * Every abort path is decided before a provider call, in this order:
 *
 *  1. row already terminal          → no-op
 *  2. AiGate + payload policy       → failed/<errorCode>, 0 request
 *  3. kunci missing / not approved  → key_pending, 0 request
 *  4. empty answer ('', '-')        → done / tidak_menjawab, 0 request
 *  5. token estimate over budget    → failed/CONTEXT_OVERFLOW, 0 request
 *
 * Only the happy path reaches the provider; each call is recorded in
 * `ai_evaluation_attempts` and its model is compared with `chat_model` so a
 * silent provider-side model change is surfaced as `model_drift`.
 *
 * Notes:
 * - `ai_evaluations` has no `error_code` / `verdict` column yet (owned by
 *   T22 / T23 migrations), so the stable code travels with the exception
 *   while the status alone is persisted; the verdict lives in raw_response.
 * - The stored `answer_hash` is recomputed from the answer actually used, so
 *   a truncation (spec E5) updates it on the same row — the row itself is
 *   never re-created.
 */
class EvaluateAnswer implements ShouldQueue
{
    use Queueable;

    /**
     * 1 initial run + 3 retries (spec E8).
     */
    public int $tries = 4;

    /**
     * Seconds to wait before retry 1, 2 and 3.
     *
     * @var list<int>
     */
    public array $backoff = [30, 120, 300];

    /**
     * Worker timeout in seconds; matches the client timeout.
     */
    public int $timeout = 90;

    public const ERROR_CONTEXT_OVERFLOW = 'CONTEXT_OVERFLOW';

    public const VERDICT_TIDAK_MENJAWAB = 'tidak_menjawab';

    /**
     * Appended to an answer that had to be cut to `max_answer_chars`.
     */
    public const TRUNCATION_MARKER = '[…dipotong';

    /**
     * Rough characters-per-token ratio for the estimate (no tokenizer in v1).
     */
    public const CHARS_PER_TOKEN = 4;

    /**
     * Characters reserved for the fixed prompt scaffolding.
     */
    public const PROMPT_OVERHEAD_CHARS = 600;

    public function __construct(public AiEvaluation $evaluation) {}

    public function handle(AiClientManager $manager, AiGate $gate, PromptBuilder $promptBuilder): void
    {
        $evaluation = AiEvaluation::query()->find($this->evaluation->getKey());

        if ($evaluation === null || $evaluation->isTerminal()) {
            return;
        }

        $evaluation->forceFill(['status' => AiEvaluation::STATUS_PROCESSING])->save();

        try {
            $this->evaluate($evaluation, $manager, $gate, $promptBuilder);
        } catch (Throwable $exception) {
            $this->fail($evaluation, $exception);
        }
    }

    private function evaluate(
        AiEvaluation $evaluation,
        AiClientManager $manager,
        AiGate $gate,
        PromptBuilder $promptBuilder,
    ): void {
        $config = $this->resolveConfig($evaluation);

        try {
            $gate->assertEnabled($config);
            $config->assertPayloadPolicy();
        } catch (Throwable $exception) {
            $this->failWithCode($evaluation, $this->errorCodeOf($exception));

            return;
        }

        $item = $this->resolveItem($evaluation);

        if ($item === null || $item->jawaban_standar_status !== SoalVersionItem::STATUS_APPROVED) {
            $evaluation->forceFill([
                'status' => AiEvaluation::STATUS_KEY_PENDING,
                'driver' => $config->driver,
            ])->save();

            return;
        }

        $evaluation->forceFill(['max_score' => (int) $item->max_score])->save();

        $answer = $this->answerText($evaluation);

        if ($this->isBlankAnswer($answer)) {
            $evaluation->forceFill([
                'status' => AiEvaluation::STATUS_DONE,
                'verdict' => self::VERDICT_TIDAK_MENJAWAB,
                'finished_at' => now(),
                'ai_score' => 0,
                'raw_response' => json_encode([
                    'verdict' => self::VERDICT_TIDAK_MENJAWAB,
                    'score' => 0,
                    'confidence' => 1.0,
                    'alasan' => 'Tidak dijawab.',
                ], JSON_UNESCAPED_UNICODE),
                'driver' => $config->driver,
                'model_drift' => false,
                'tokens_in' => 0,
                'tokens_out' => 0,
                'latency_ms' => 0,
            ])->save();

            return;
        }

        $answer = $this->truncateAnswer($answer, $this->maxAnswerChars($config));
        $evaluation->forceFill(['answer_hash' => hash('sha256', $answer)])->save();

        $messages = $promptBuilder->build([
            'soal' => (string) $item->teks_snapshot,
            'kunci' => (string) $item->jawaban_standar,
            'jawaban' => $answer,
            'max_score' => (int) $item->max_score,
        ]);

        $estimatedTokens = $this->estimateTokens($promptBuilder->flatten($messages));

        if ($estimatedTokens > (int) $config->max_context_tokens) {
            throw new AiUnavailableException(
                self::ERROR_CONTEXT_OVERFLOW,
                'Estimasi '.$estimatedTokens.' token melebihi batas '.$config->max_context_tokens.'.',
            );
        }

        $client = $manager->forChat($config);
        $attemptNo = (int) $evaluation->attempts()->max('attempt_no') + 1;
        $startedAt = microtime(true);

        try {
            $response = $client->chat($messages);
        } catch (Throwable $exception) {
            $this->recordAttempt($evaluation, $attemptNo, [
                'http_status' => $this->httpStatusOf($exception),
                'outcome' => 'failed',
                'error_code' => $this->errorCodeOf($exception),
                'latency_ms' => $this->elapsedMs($startedAt),
            ]);

            throw $exception;
        }

        $latencyMs = $this->elapsedMs($startedAt);
        $responseModel = $this->responseModelOf($response);
        $usage = $this->usageOf($response, $promptBuilder->flatten($messages), (string) $response['content']);

        $this->recordAttempt($evaluation, $attemptNo, [
            'http_status' => 200,
            'outcome' => 'done',
            'response_model' => $responseModel,
            'tokens_in' => $usage['tokens_in'],
            'tokens_out' => $usage['tokens_out'],
            'latency_ms' => $latencyMs,
        ]);

        $parsed = $this->parseVerdict((string) $response['content'], (int) $item->max_score);

        $evaluation->forceFill([
            'status' => AiEvaluation::STATUS_DONE,
            'verdict' => $parsed['verdict'],
            'finished_at' => now(),
            'ai_score' => $parsed['score'],
            'max_score' => (int) $item->max_score,
            'raw_response' => (string) $response['content'],
            'driver' => $config->driver,
            'response_model' => $responseModel,
            'model_drift' => $responseModel !== $config->chat_model,
            'tokens_in' => $usage['tokens_in'],
            'tokens_out' => $usage['tokens_out'],
            'latency_ms' => $latencyMs,
        ])->save();

        Log::info('ai.evaluation_done', [
            'ai_evaluation_id' => $evaluation->getKey(),
            'verdict' => $parsed['verdict'],
        ]);
    }

    /**
     * The config snapshot the evaluation was queued with. Missing configs
     * abort with PROVIDER_NOT_CONFIGURED before any gate check.
     */
    private function resolveConfig(AiEvaluation $evaluation): AiConfig
    {
        $config = AiConfig::query()->find($evaluation->ai_config_id);

        if ($config === null) {
            throw new AiUnavailableException(
                AiUnavailableException::CODE_PROVIDER_NOT_CONFIGURED,
                'Baris evaluasi tidak merujuk ai_configs yang ada.',
            );
        }

        return $config;
    }

    /**
     * The key row is addressed by version_set_id + soal_id + jawaban_type;
     * the evaluation stores the ANSWER id, so it is mapped through the
     * answer row (tp → jawaban_tps, tm → jawaban_mandiris).
     */
    private function resolveItem(AiEvaluation $evaluation): ?SoalVersionItem
    {
        $answer = $this->answerRow($evaluation);

        if ($answer === null) {
            return null;
        }

        return SoalVersionItem::query()
            ->where('version_set_id', $evaluation->version_set_id)
            ->where('soal_type', $evaluation->jawaban_type)
            ->where('soal_id', $answer->soal_id)
            ->first();
    }

    private function answerRow(AiEvaluation $evaluation): JawabanTp|JawabanMandiri|null
    {
        $model = $evaluation->jawaban_type === SoalVersionItem::SOAL_TM
            ? JawabanMandiri::class
            : JawabanTp::class;

        return $model::query()->find($evaluation->jawaban_id);
    }

    private function answerText(AiEvaluation $evaluation): string
    {
        return (string) ($this->answerRow($evaluation)?->jawaban ?? '');
    }

    private function isBlankAnswer(string $answer): bool
    {
        $trimmed = trim($answer);

        return $trimmed === '' || $trimmed === '-';
    }

    private function maxAnswerChars(AiConfig $config): int
    {
        $limit = (int) $config->max_answer_chars;

        return $limit > 0 ? $limit : (int) config('ai.max_answer_chars', 10000);
    }

    private function truncateAnswer(string $answer, int $limit): string
    {
        if (mb_strlen($answer) <= $limit) {
            return $answer;
        }

        $marker = self::TRUNCATION_MARKER.' '.$limit.' karakter]';

        return mb_substr($answer, 0, $limit).$marker;
    }

    private function estimateTokens(string $prompt): int
    {
        return (int) ceil((mb_strlen($prompt) + self::PROMPT_OVERHEAD_CHARS) / self::CHARS_PER_TOKEN);
    }

    /**
     * Accept the first JSON object found in the answer, validate the verdict
     * and clamp the score into [0, max_score].
     *
     * @return array{verdict: string, score: float}
     *
     * @throws AiUnavailableException
     */
    private function parseVerdict(string $content, int $maxScore): array
    {
        $payload = json_decode(trim($content), true);

        if (! is_array($payload)) {
            $start = strpos($content, '{');
            $end = strrpos($content, '}');

            $payload = $start !== false && $end !== false && $end > $start
                ? json_decode(substr($content, $start, $end - $start + 1), true)
                : null;
        }

        $verdict = is_array($payload) ? ($payload['verdict'] ?? null) : null;

        if (! is_string($verdict) || ! in_array($verdict, PromptBuilder::VERDICTS, true)) {
            throw new AiUnavailableException(
                AiUnavailableException::CODE_CONTRACT_VIOLATION,
                'Respons model bukan JSON verdict yang valid.',
            );
        }

        $score = is_array($payload) ? ($payload['score'] ?? null) : null;
        $score = is_numeric($score) ? (float) $score : ($verdict === self::VERDICT_TIDAK_MENJAWAB ? 0.0 : (float) $maxScore);

        return [
            'verdict' => $verdict,
            'score' => round(max(0.0, min((float) $maxScore, $score)), 2),
        ];
    }

    private function responseModelOf(array $response): ?string
    {
        $model = $response['model'] ?? null;

        return is_string($model) && trim($model) !== '' ? $model : null;
    }

    /**
     * Provider usage when present, otherwise a character-based estimate.
     *
     * @return array{tokens_in: int, tokens_out: int}
     */
    private function usageOf(array $response, string $prompt, string $content): array
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        $tokensIn = $usage['prompt_tokens'] ?? null;
        $tokensOut = $usage['completion_tokens'] ?? null;

        return [
            'tokens_in' => is_numeric($tokensIn)
                ? (int) $tokensIn
                : $this->estimateTokens($prompt),
            'tokens_out' => is_numeric($tokensOut)
                ? (int) $tokensOut
                : (int) ceil(mb_strlen($content) / self::CHARS_PER_TOKEN),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recordAttempt(AiEvaluation $evaluation, int $attemptNo, array $attributes): void
    {
        $evaluation->attempts()->create(array_merge([
            'attempt_no' => $attemptNo,
        ], $attributes));
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function httpStatusOf(Throwable $exception): ?int
    {
        if ($exception instanceof AiUnavailableException) {
            $decoded = json_decode((string) strstr($exception->getMessage(), '{'), true);

            if (is_array($decoded) && isset($decoded['status']) && is_numeric($decoded['status'])) {
                return (int) $decoded['status'];
            }
        }

        return null;
    }

    private function errorCodeOf(Throwable $exception): string
    {
        $method = method_exists($exception, 'getErrorCode') ? 'getErrorCode' : 'errorCode';

        if (method_exists($exception, $method)) {
            $code = $exception->{$method}();

            if (is_string($code) && $code !== '') {
                return $code;
            }
        }

        return AiUnavailableException::CODE_EXTERNAL_ERROR;
    }

    private function fail(AiEvaluation $evaluation, Throwable $exception): void
    {
        $this->failWithCode($evaluation, $this->errorCodeOf($exception));
    }

    private function failWithCode(AiEvaluation $evaluation, string $errorCode): void
    {
        $evaluation->forceFill([
            'status' => AiEvaluation::STATUS_FAILED,
            'error_code' => $errorCode,
            'finished_at' => now(),
        ])->save();

        Log::warning('ai.evaluation_failed', [
            'ai_evaluation_id' => $evaluation->getKey(),
            'error_code' => $errorCode,
        ]);
    }
}
