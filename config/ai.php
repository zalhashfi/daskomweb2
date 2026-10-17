<?php

/*
|--------------------------------------------------------------------------
| AI Provider Configuration
|--------------------------------------------------------------------------
|
| v1 talks to exactly one provider family: an OpenAI-compatible gateway
| (9router). There is no fallback provider - an unknown/missing driver fails
| loudly in App\Services\Ai\AiClientManager, and `fallback_driver` is
| intentionally absent from this file (spec Q22a/Q30).
|
*/

return [

    /*
    | Driver used for chat. v1 only knows `openai_compatible`.
    */
    'default_driver' => 'openai_compatible',

    'openai_compatible' => [
        'base_url' => env('AI_BASE_URL', 'http://127.0.0.1:20128'),

        /*
        | Bearer token for the gateway. Never logged, never audited.
        */
        'api_key' => env('AI_API_KEY'),

        /*
        | Model ids use the gateway `provider/model` format.
        */
        'chat_model' => env('AI_CHAT_MODEL', 'gemini/gemini-flash'),

        /*
        | Produk model. It is part of the chat model digest, so it must be
        | set explicitly in production for the snapshot to be meaningful.
        */
        'chat_model_version' => env('AI_CHAT_MODEL_VERSION'),

        'embed_model' => env('AI_EMBED_MODEL', 'gemini/gemini-embedding-001'),

        /*
        | HTTP timeout in seconds; matches the evaluation job timeout.
        */
        'timeout' => 90,
    ],

    /*
    | Embedding profiles. `dimension` is taken from the provider
    | documentation (not probed through the API) and verified against the
    | first returned vector length.
    */
    'embed_profiles' => [
        'ninerouter' => [
            'name' => env('AI_EMBED_MODEL', 'gemini/gemini-embedding-001'),
            'dimension' => (int) env('AI_EMBED_DIMENSION', 3072),
        ],
    ],

    /*
    | Maximum characters of an AI answer kept in ai_evaluations.
    */
    'max_answer_chars' => 10000,

    /*
    | Token budget per module; exhausted budget stops outbound calls.
    */
    'budget_tokens_per_modul' => (int) env('AI_BUDGET_TOKENS_PER_MODUL', 500000),

];
