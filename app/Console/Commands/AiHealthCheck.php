<?php

namespace App\Console\Commands;

use App\Services\Ai\ProviderHealth;
use Illuminate\Console\Command;

/**
 * Refreshes the AI provider health (`GET {base}/api/health`).
 *
 *   php artisan ai:health-check
 *
 * Makes a provider request only when there is queued work and the
 * {@see ProviderHealth::TTL_SECONDS} window has elapsed:
 *
 *  - no non-terminal evaluation : 0 request, `idle` cached, exit 0;
 *  - TTL not elapsed            : 0 request, cached result reused, exit 0;
 *  - TTL elapsed                : one request; 200 → `up`, failures →
 *    `down` on the third consecutive one, 404 → fallback on the last
 *    provider failure timestamp.
 *
 * The verdict lands in the cache and in `ai_provider_status`. Exit 0
 * whenever the check itself ran (a `down` provider is a result, not a
 * command error).
 */
class AiHealthCheck extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ai:health-check';

    /**
     * @var string
     */
    protected $description = 'Refresh the AI provider health status (GET /api/health) for queued work';

    public function handle(ProviderHealth $health): int
    {
        $result = ProviderHealth::fromConfig()->check();

        $this->line(sprintf(
            'state=%s status=%s failures=%d checked_at=%s',
            $result['state'],
            $result['status'] ?? '-',
            $result['consecutive_failures'],
            $result['checked_at'] ?? '-',
        ));

        if ($result['last_error'] !== null) {
            $this->line('last_error='.$result['last_error']);
        }

        return self::SUCCESS;
    }
}
