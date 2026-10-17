<?php

namespace App\Services\Ai;

use App\Exceptions\AiDisabledException;
use App\Exceptions\ExternalPayloadDisabledException;
use App\Exceptions\ProviderPolicyUnverifiedException;
use App\Models\AiConfig;

/**
 * Single gate every cost-incurring AI path must pass before touching the
 * provider (submit, job, retry, regrade, ingest).
 *
 * Checks run in a fixed order so the reported `errorCode` is deterministic:
 *
 *  1. `ai_enabled`               → AI_DISABLED
 *  2. `allow_external_payload`   → EXTERNAL_PAYLOAD_DISABLED
 *  3. `provider_policy_verified_at` → PROVIDER_POLICY_UNVERIFIED
 *
 * Comparison is strict (`!== true`), never `empty()`: `null`, `0`, `'false'`
 * and `1`/`'true'` strings all count as "not allowed".
 */
class AiGate
{
    /**
     * @throws AiDisabledException
     * @throws ExternalPayloadDisabledException
     * @throws ProviderPolicyUnverifiedException
     */
    public function assertEnabled(AiConfig $config): void
    {
        if ($config->ai_enabled !== true) {
            throw new AiDisabledException;
        }

        if ($config->allow_external_payload !== true) {
            throw new ExternalPayloadDisabledException;
        }

        if ($config->provider_policy_verified_at === null) {
            throw new ProviderPolicyUnverifiedException;
        }
    }
}
