<?php

namespace App\Http\Controllers\API;

use App\Exceptions\AiUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAiProviderCredentialRequest;
use App\Models\AiConfig;
use App\Models\AiProviderCredential;
use App\Services\Ai\ModelCatalog;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

/**
 * Operator control of the AI gateway credentials (base URL + API key).
 *
 *  - GET  /api-v1/ai-provider-credentials       `view-ai-metrics`
 *  - POST /api-v1/ai-provider-credentials/test  `manage-ai-gate`
 *  - POST /api-v1/ai-provider-credentials       `manage-ai-gate`
 *
 * The credential is the `ai_provider_credentials` active row; when none exists
 * the `ai.openai_compatible.*` configuration (`.env`) is the effective source.
 * The API key is never returned in full: only the masked form is exposed and
 * only the last four characters ever reach the audit log.
 */
class AiProviderCredentialController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->snapshot(),
        ]);
    }

    /**
     * Probe the provider with the URL and key of the request body; nothing is
     * persisted. The key is never echoed back nor logged.
     */
    public function test(SaveAiProviderCredentialRequest $request): JsonResponse
    {
        try {
            $models = (new ModelCatalog(
                baseUrl: $request->string('base_url'),
                apiKey: $request->string('api_key'),
                timeout: 15,
            ))->refresh();
        } catch (AiUnavailableException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [
                    'reachable' => false,
                ],
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'reachable' => true,
                'models' => count($models),
            ],
        ]);
    }

    public function save(SaveAiProviderCredentialRequest $request): JsonResponse
    {
        $baseUrl = rtrim((string) $request->string('base_url'), '/');
        $apiKey = (string) $request->string('api_key');

        $credential = AiProviderCredential::store($baseUrl, $apiKey);

        app(AuditLogger::class)->record(
            'ai.provider.credentials.updated',
            'Kredensial provider AI diperbarui.',
            [
                'reason' => (string) $request->string('reason'),
                'host' => parse_url($baseUrl, PHP_URL_HOST) ?: null,
                'api_key_hint' => substr($apiKey, -4),
            ],
            AiProviderCredential::class,
            $credential->getKey(),
            'asisten',
            $request->user('asisten')?->getKey(),
        );

        return response()->json([
            'status' => 'success',
            'data' => $this->snapshot(),
        ]);
    }

    /**
     * Configuration snapshot: models come from `AiConfig::active()`, the
     * credentials from the active row (or the `.env` fallback).
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $config = AiConfig::active();
        $credential = AiProviderCredential::active();

        if ($credential === null) {
            $configuredKey = config('ai.openai_compatible.api_key');
            $hasKey = filled($configuredKey);

            return [
                'base_url' => config('ai.openai_compatible.base_url'),
                'api_key_masked' => $hasKey ? '••••' : null,
                'source' => 'env',
                'chat_model' => $config->chat_model,
                'embed_model' => $config->embed_model_name,
                'api_key_configured' => $hasKey,
            ];
        }

        return [
            'base_url' => $credential->base_url,
            'api_key_masked' => $credential->maskedApiKey(),
            'source' => 'database',
            'chat_model' => $config->chat_model,
            'embed_model' => $config->embed_model_name,
            'api_key_configured' => true,
        ];
    }
}
