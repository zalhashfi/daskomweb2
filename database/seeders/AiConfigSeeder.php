<?php

namespace Database\Seeders;

use App\Models\AiConfig;
use App\Services\AuditLogger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the single active AI configuration snapshot (spec §6 `ai_configs`,
 * T18c).
 *
 * Everything provider-specific is read from config/ai.php so the row records
 * what was actually deployed: the chat model digest, the embedding profile
 * (name + dimension) and the per-module token budget. The row is deliberately
 * inert (`ai_enabled=false`, `allow_external_payload=false`,
 * `provider_policy_verified_at=null`) until an operator verifies the provider
 * policy and switches it on.
 *
 * Idempotent: running twice keeps exactly one active row and records the
 * `ai.provider.activated` audit entry once per actual activation.
 */
class AiConfigSeeder extends Seeder
{
    public function run(): void
    {
        $driver = config('ai.default_driver', 'openai_compatible');
        $baseUrl = (string) config('ai.openai_compatible.base_url');
        $chatModel = (string) config('ai.openai_compatible.chat_model');
        $chatModelVersion = (string) config('ai.openai_compatible.chat_model_version');

        $embedDriver = 'ninerouter';
        $embedProfile = (array) config('ai.embed_profiles.ninerouter');
        $embedModelName = (string) ($embedProfile['name'] ?? config('ai.openai_compatible.embed_model'));
        $embeddingDimension = (int) ($embedProfile['dimension'] ?? 0);

        $attributes = [
            'driver' => $driver,
            'embed_driver' => $embedDriver,
            'base_url' => $baseUrl,
            'base_url_host' => parse_url($baseUrl, PHP_URL_HOST) ?: null,
            'chat_model' => $chatModel,
            'model_chat_digest' => hash('sha256', $chatModel.'|'.$chatModelVersion),
            'embed_model_name' => $embedModelName,
            'embed_model_digest' => hash('sha256', $embedModelName.'|'.$embeddingDimension),
            'embedding_dimension' => $embeddingDimension,
            'retrieval_top_k' => 4,
            'max_context_tokens' => 4000,
            'max_answer_chars' => (int) config('ai.max_answer_chars', 10000),
            'allow_external_payload' => false,
            'ai_enabled' => false,
            'budget_tokens_per_modul' => (int) config('ai.budget_tokens_per_modul', 0),
            'provider_policy_verified_at' => null,
        ];

        $activated = DB::transaction(function () use ($attributes): ?AiConfig {
            $active = AiConfig::query()
                ->where('is_active', true)
                ->orderByDesc('id')
                ->first();

            if ($active !== null && $this->matches($active, $attributes)) {
                return null;
            }

            if ($active !== null) {
                $active->is_active = false;
                $active->save();
            }

            return AiConfig::query()->create($attributes + ['is_active' => true]);
        });

        if ($activated === null) {
            $this->command?->info('AiConfig sudah aktif dengan snapshot yang sama; tidak ada perubahan.');

            return;
        }

        $this->recordActivation($activated);

        $this->command?->info(sprintf(
            'AiConfig aktif #%d (%s, %s).',
            $activated->getKey(),
            $activated->driver,
            $activated->chat_model,
        ));
    }

    /**
     * Whether the active row already carries the configured snapshot.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function matches(AiConfig $active, array $attributes): bool
    {
        foreach ($attributes as $column => $expected) {
            $actual = $active->getAttribute($column);

            if ($actual === $expected) {
                continue;
            }

            // Normalise scalars so DB round-trips (?int/string) compare cleanly.
            if ($actual !== null && $expected !== null && (string) $actual === (string) $expected) {
                continue;
            }

            if (is_bool($expected) && (bool) $actual === $expected) {
                continue;
            }

            if (in_array($column, ['allow_external_payload', 'ai_enabled'], true)
                && (int) $actual === (int) $expected) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Audit the activation with the gateway host only - never the API key or
     * an Authorization header.
     */
    private function recordActivation(AiConfig $config): void
    {
        app(AuditLogger::class)->record(
            'ai.provider.activated',
            'Konfigurasi provider AI diaktifkan.',
            [
                'ai_config_id' => $config->getKey(),
                'driver' => $config->driver,
                'embed_driver' => $config->embed_driver,
                'host' => $config->base_url_host ?? (parse_url((string) $config->base_url, PHP_URL_HOST) ?: null),
                'chat_model' => $config->chat_model,
                'model_chat_digest' => $config->model_chat_digest,
                'embed_model_name' => $config->embed_model_name,
                'embed_model_digest' => $config->embed_model_digest,
                'embedding_dimension' => $config->embedding_dimension,
                'budget_tokens_per_modul' => $config->budget_tokens_per_modul,
                'allow_external_payload' => $config->allow_external_payload,
                'ai_enabled' => $config->ai_enabled,
            ],
            subjectType: 'ai_config',
            subjectId: (int) $config->getKey(),
            actorType: 'system',
        );
    }
}
