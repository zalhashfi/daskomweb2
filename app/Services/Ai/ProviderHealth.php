<?php

namespace App\Services\Ai;

use App\Exceptions\AiUnavailableException;
use App\Models\AiEvaluation;
use App\Models\AiProviderStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Health of the AI provider (`GET {base}/api/health`), refreshed lazily by
 * `php artisan ai:health-check`.
 *
 * The probe only runs when there is queued work, and at most once per
 * {@see TTL_SECONDS}:
 *
 *  - no non-terminal evaluation     → 0 request, the cache records `idle`;
 *  - a probe younger than the TTL   → 0 request, the cached result is reused;
 *  - TTL elapsed                    → exactly ONE `GET {base}/api/health`:
 *       * 200                                → `up`, failure streak reset;
 *       * anything else / connection error   → streak + 1, `down` on the
 *                                               third consecutive failure;
 *       * 404 (endpoint absent)              → fallback: the last provider
 *                                              failure timestamp is used and
 *                                              cached for the same TTL.
 *
 * The last provider failure timestamp comes from `ai_evaluation_attempts`
 * (the append-only log of outbound calls): the newest attempt with an
 * `outcome = failed` or an `error_code`, i.e. the moment a provider call was
 * last known to fail. With a failure inside the TTL the provider is `down`,
 * otherwise it is assumed `up`.
 *
 * Every probe result is written to the cache (so the next run inside the TTL
 * makes no request) and to `ai_provider_status`.
 */
class ProviderHealth
{
    public const TTL_SECONDS = 60;

    public const DOWN_AFTER_FAILURES = 3;

    public const HEALTH_PATH = '/api/health';

    public const CACHE_KEY = 'ai:provider_health';

    public const TIMEOUT_SECONDS = 10;

    /**
     * Attempt logs considered as evidence that the provider failed.
     */
    private const FAILED_OUTCOMES = ['failed', 'error'];

    /**
     * @param  string|null  $baseUrl  Gateway root, e.g. `https://router.local`.
     * @param  string|null  $apiKey  Bearer token; never logged.
     */
    public function __construct(
        public readonly ?string $baseUrl = null,
        public readonly ?string $apiKey = null,
    ) {}

    /**
     * Build the probe from `config('ai.openai_compatible.*')`.
     */
    public static function fromConfig(): self
    {
        $config = config('ai.openai_compatible', []);

        return new self(
            baseUrl: $config['base_url'] ?? null,
            apiKey: $config['api_key'] ?? null,
        );
    }

    /**
     * Refresh the provider health when there is queued work and the TTL has
     * elapsed.
     *
     * `state` in the returned array is `idle`, `cached` or `probed`.
     *
     * @return array{state: string, status: string|null, consecutive_failures: int, checked_at: string|null, last_error: string|null}
     */
    public function check(): array
    {
        if (! $this->hasNonTerminalEvaluations()) {
            $idle = [
                'state' => 'idle',
                'status' => null,
                'consecutive_failures' => 0,
                'checked_at' => Carbon::now()->toIso8601String(),
                'last_error' => null,
            ];

            Cache::put(self::CACHE_KEY, $idle, now()->addSeconds(self::TTL_SECONDS));

            return $idle;
        }

        $previous = $this->cachedResult();

        if ($previous !== null && $this->isFresh($previous)) {
            return array_merge($previous, ['state' => 'cached']);
        }

        $previousFailures = $this->consecutiveFailures($previous);
        $result = $this->probe($previousFailures);

        Cache::put(self::CACHE_KEY, $result, now()->addSeconds(self::TTL_SECONDS));

        return array_merge($result, ['state' => 'probed']);
    }

    /**
     * `true` while at least one evaluation still needs the provider.
     */
    public function hasNonTerminalEvaluations(): bool
    {
        return AiEvaluation::query()
            ->whereNotIn('status', AiEvaluation::TERMINAL_STATUSES)
            ->exists();
    }

    /**
     * Cached probe result, or null when missing / unreadable / idle (an idle
     * snapshot never satisfies a non-empty queue).
     *
     * @return array{status: string|null, consecutive_failures: int, checked_at: string|null, last_error: string|null}|null
     */
    private function cachedResult(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (! is_array($cached) || ($cached['state'] ?? null) === 'idle' || ! isset($cached['checked_at'])) {
            return null;
        }

        unset($cached['state'], $cached['note']);

        return $cached;
    }

    /**
     * Whether the cached probe is younger than the TTL.
     *
     * @param  array{checked_at: string|null}  $result
     */
    private function isFresh(array $result): bool
    {
        if ($result['checked_at'] === null) {
            return false;
        }

        return abs(Carbon::now()->getTimestamp() - Carbon::parse($result['checked_at'])->getTimestamp()) < self::TTL_SECONDS;
    }

    /**
     * @param  array{consecutive_failures?: int}|null  $previous
     */
    private function consecutiveFailures(?array $previous): int
    {
        if ($previous !== null && isset($previous['consecutive_failures'])) {
            return (int) $previous['consecutive_failures'];
        }

        return (int) (AiProviderStatus::query()->latest('id')->value('consecutive_failures') ?? 0);
    }

    /**
     * One `GET {base}/api/health` and its verdict.
     *
     * @return array{status: string|null, consecutive_failures: int, checked_at: string|null, last_error: string|null}
     */
    private function probe(int $previousFailures): array
    {
        if ($this->baseUrl === null || trim($this->baseUrl) === '') {
            return $this->recordFailure($previousFailures, 'ai.openai_compatible.base_url kosong.');
        }

        try {
            $response = Http::acceptJson()
                ->withToken((string) $this->apiKey)
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(min(self::TIMEOUT_SECONDS, 5))
                ->get(rtrim($this->baseUrl, '/').self::HEALTH_PATH);
        } catch (ConnectionException $exception) {
            return $this->recordFailure($previousFailures, $exception->getMessage());
        }

        if ($response->status() === 404) {
            return $this->fallback($previousFailures);
        }

        if ($response->successful()) {
            return $this->recordStatus(AiProviderStatus::STATUS_UP, 0, null);
        }

        return $this->recordFailure(
            $previousFailures,
            AiUnavailableException::CODE_EXTERNAL_ERROR.' (HTTP '.$response->status().')',
        );
    }

    /**
     * The provider has no health endpoint: decide from the last failure of a
     * real provider call instead of probing again.
     *
     * @return array{status: string|null, consecutive_failures: int, checked_at: string|null, last_error: string|null}
     */
    private function fallback(int $previousFailures): array
    {
        $lastFailureAt = $this->lastProviderFailureAt();
        $now = Carbon::now();

        if ($lastFailureAt !== null && abs($now->getTimestamp() - $lastFailureAt->getTimestamp()) < self::TTL_SECONDS) {
            return $this->recordStatus(
                AiProviderStatus::STATUS_DOWN,
                $previousFailures + 1,
                'Endpoint '.self::HEALTH_PATH.' tidak tersedia (404); kegagalan provider terakhir '.$lastFailureAt->toIso8601String().'.',
            );
        }

        return $this->recordStatus(
            AiProviderStatus::STATUS_UP,
            0,
            null,
            'Endpoint '.self::HEALTH_PATH.' tidak tersedia (404); tidak ada kegagalan provider dalam '.self::TTL_SECONDS.' detik terakhir.',
        );
    }

    /**
     * Moment of the newest failed provider call (chat or embedding), or null
     * when the provider never failed.
     */
    private function lastProviderFailureAt(): ?Carbon
    {
        $attempt = DB::table('ai_evaluation_attempts')
            ->where(function ($query): void {
                $query->where('outcome', 'failed')
                    ->orWhereNotNull('error_code');
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['created_at', 'updated_at']);

        if ($attempt === null) {
            return null;
        }

        return Carbon::parse($attempt->updated_at ?? $attempt->created_at);
    }

    /**
     * @return array{status: string|null, consecutive_failures: int, checked_at: string|null, last_error: string|null}
     */
    private function recordFailure(int $previousFailures, string $error): array
    {
        $failures = $previousFailures + 1;
        $status = $failures >= self::DOWN_AFTER_FAILURES
            ? AiProviderStatus::STATUS_DOWN
            : AiProviderStatus::STATUS_UP;

        return $this->recordStatus($status, $failures, $error);
    }

    /**
     * Write the verdict to the cache and `ai_provider_status`. `$note`
     * documents a fallback in the cache only, never in `last_error`.
     *
     * @return array{status: string|null, consecutive_failures: int, checked_at: string|null, last_error: string|null}
     */
    private function recordStatus(string $status, int $failures, ?string $lastError, ?string $note = null): array
    {
        $now = Carbon::now();

        AiProviderStatus::query()->create([
            'status' => $status,
            'checked_at' => $now,
            'consecutive_failures' => $failures,
            'last_error' => $lastError,
        ]);

        $result = [
            'status' => $status,
            'consecutive_failures' => $failures,
            'checked_at' => $now->toIso8601String(),
            'last_error' => $lastError,
        ];

        Cache::put(self::CACHE_KEY, $note === null ? $result : array_merge($result, ['note' => $note]), now()->addSeconds(self::TTL_SECONDS));

        return $result;
    }
}
