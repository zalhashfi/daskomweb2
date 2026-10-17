<?php

namespace App\Console\Commands;

use App\Exceptions\AiUnavailableException;
use App\Exceptions\ModelCatalogException;
use App\Models\AiConfig;
use App\Services\Ai\ModelCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Operator confirmation that the provider is on a paid tier with no training
 * on our data (spec T08y).
 *
 * Nothing here is inferred: verifying the policy is a human decision, so the
 * command refuses to run - and never touches the provider - unless the
 * operator passes `--confirm-paid-no-training`.
 *
 * With the flag set, the configured chat model is validated against
 * `GET /v1/models`; only then is `provider_policy_verified_at` stamped on the
 * ACTIVE `ai_configs` row and the audit `ai.provider.policy_verified` written.
 * The audit carries host, model and flag only - never the API key/token.
 */
class AiVerifyProviderPolicy extends Command
{
    public const AUDIT_EVENT = 'ai.provider.policy_verified';

    protected $signature = 'ai:verify-provider
        {--confirm-paid-no-training : Operator confirms the provider account is paid and does not train on submitted data}';

    protected $description = 'Verify the provider chat model and stamp provider_policy_verified_at (requires --confirm-paid-no-training).';

    public function handle(): int
    {
        $confirmed = (bool) $this->option('confirm-paid-no-training');

        if (! $confirmed) {
            $this->error('Konfirmasi diperlukan: jalankan ulang dengan --confirm-paid-no-training setelah memastikan akun provider berbayar dan tidak melatih model dengan data yang dikirim.');

            return self::FAILURE;
        }

        $model = config('ai.openai_compatible.chat_model');

        if (! is_string($model) || trim($model) === '') {
            $this->error('ai.openai_compatible.chat_model kosong; kebijakan provider tidak dapat diverifikasi.');

            return self::FAILURE;
        }

        try {
            ModelCatalog::fromConfig()->assertChatModel($model);
        } catch (ModelCatalogException $e) {
            $this->error('Model "'.$model.'" tidak valid: '.$e->getMessage());

            return self::FAILURE;
        } catch (AiUnavailableException $e) {
            $this->error('Katalog model tidak dapat dibaca: '.$e->getMessage());

            return self::FAILURE;
        }

        // Stamped on the ACTIVE row (AiConfig::active() provisions the default
        // inactive row when none exists yet). forceFill skips mass assignment;
        // save() routes the immutable-column change through the revision
        // mechanism, which deactivates the old row and activates the newly
        // stamped snapshot - hence the re-read below.
        AiConfig::active()
            ->forceFill(['provider_policy_verified_at' => now()])
            ->save();

        $config = AiConfig::active();

        $this->audit($model, $confirmed);

        $this->info('Kebijakan provider diverifikasi untuk model "'.$model.'" (ai_configs #'.$config->getKey().').');

        return self::SUCCESS;
    }

    /**
     * Host/model/flag only; the bearer token is never part of the context.
     */
    private function audit(string $model, bool $confirmed): void
    {
        Log::info(self::AUDIT_EVENT, [
            'host' => $this->host(),
            'model' => $model,
            'confirm_paid_no_training' => $confirmed,
        ]);
    }

    private function host(): ?string
    {
        $baseUrl = config('ai.openai_compatible.base_url');

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            return null;
        }

        return parse_url($baseUrl, PHP_URL_HOST) ?: null;
    }
}
