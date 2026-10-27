<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Encrypted gateway credentials entered by an operator through the settings
 * UI (see Migration 2026_10_26_000000_create_ai_provider_credentials_table).
 *
 * `api_key` is stored with Laravel's `encrypted` cast, so it never appears
 * in plain text in the database, in a dump, or in an exception. It is also
 * excluded from serialisation (`$hidden`), so no controller can leak it by
 * returning the model: only `maskedApiKey()` is ever exposed.
 *
 * Saves are append-only: writing any of `base_url`, `base_url_host` or
 * `api_key` deactivates the current row and inserts a new active one, so the
 * previous credential can be audited without ever being readable again.
 *
 * When no active row exists the application falls back to
 * `config('ai.openai_compatible.*')`, which keeps the `.env` workflow working.
 */
class AiProviderCredential extends Model
{
    protected $table = 'ai_provider_credentials';

    protected $fillable = [
        'base_url',
        'base_url_host',
        'api_key',
        'api_key_hint',
        'is_active',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'is_active' => 'boolean',
    ];

    /**
     * The single active credential row, or null when the operator has never
     * saved one (the `.env` values then apply).
     */
    public static function active(): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Persist a new credential revision: deactivate the previous active row
     * and insert this one as active.
     */
    public static function store(string $baseUrl, string $apiKey): self
    {
        return static::getConnectionResolver()
            ->connection(static::query()->getModel()->getConnectionName())
            ->transaction(function () use ($baseUrl, $apiKey): self {
                static::query()
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'updated_at' => now()]);

                return static::query()->create([
                    'base_url' => $baseUrl,
                    'base_url_host' => parse_url($baseUrl, PHP_URL_HOST) ?: null,
                    'api_key' => $apiKey,
                    'api_key_hint' => substr($apiKey, -4),
                    'is_active' => true,
                ]);
            });
    }

    /**
     * Masked representation safe for the UI and the audit log.
     */
    public function maskedApiKey(): string
    {
        $hint = $this->api_key_hint;

        return $hint === null || $hint === '' ? '••••' : '••••'.$hint;
    }

    /**
     * The API key the gateway client must use: the stored credential when a
     * row exists with a non-empty key, otherwise `ai.openai_compatible.api_key`.
     */
    public function resolvedApiKey(): string
    {
        if ($this->exists && filled($this->api_key)) {
            return (string) $this->api_key;
        }

        return (string) config('ai.openai_compatible.api_key');
    }

    /**
     * The base URL the gateway client must use: the stored credential when a
     * row exists with a non-empty URL, otherwise the configured fallback.
     * Always returned without a trailing slash.
     */
    public function resolvedBaseUrl(): string
    {
        if ($this->exists && filled($this->base_url)) {
            return rtrim((string) $this->base_url, '/');
        }

        return rtrim((string) config('ai.openai_compatible.base_url'), '/');
    }
}
