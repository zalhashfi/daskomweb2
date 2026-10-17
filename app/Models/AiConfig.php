<?php

namespace App\Models;

use App\Exceptions\ExternalPayloadDisabledException;
use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot of the AI provider configuration.
 *
 * Rows are immutable apart from `is_active`: changing any meaningful column
 * (digests, models, limits, driver...) on an active row is turned into a
 * revision — the old row is deactivated and a new active row is inserted.
 * Only toggling `is_active` updates the row in place.
 */
class AiConfig extends Model
{
    protected $table = 'ai_configs';

    /**
     * Columns that, when changed, must produce a new revision row.
     */
    private const IMMUTABLE_COLUMNS = [
        'driver',
        'embed_driver',
        'base_url',
        'base_url_host',
        'chat_model',
        'model_chat_digest',
        'embed_model_name',
        'embed_model_digest',
        'embedding_dimension',
        'retrieval_top_k',
        'max_context_tokens',
        'max_answer_chars',
        'allow_external_payload',
        'ai_enabled',
        'budget_tokens_per_modul',
        'provider_policy_verified_at',
    ];

    protected $fillable = [
        'driver',
        'embed_driver',
        'base_url',
        'base_url_host',
        'chat_model',
        'model_chat_digest',
        'embed_model_name',
        'embed_model_digest',
        'embedding_dimension',
        'retrieval_top_k',
        'max_context_tokens',
        'max_answer_chars',
        'allow_external_payload',
        'ai_enabled',
        'budget_tokens_per_modul',
        'provider_policy_verified_at',
        'is_active',
    ];

    protected $casts = [
        'embedding_dimension' => 'integer',
        'retrieval_top_k' => 'integer',
        'max_context_tokens' => 'integer',
        'max_answer_chars' => 'integer',
        'allow_external_payload' => 'boolean',
        'ai_enabled' => 'boolean',
        'budget_tokens_per_modul' => 'integer',
        'provider_policy_verified_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Guard preventing the revision inserted by the saving listener from
     * being re-processed by itself.
     */
    private bool $writingRevision = false;

    /**
     * Return the single active configuration, creating a default one when
     * none exists yet.
     */
    public static function active(): self
    {
        $existing = static::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return static::query()->create([
            'driver' => 'openai_compatible',
            'retrieval_top_k' => 4,
            'max_context_tokens' => 4000,
            'max_answer_chars' => 10000,
            'allow_external_payload' => false,
            'ai_enabled' => false,
            'budget_tokens_per_modul' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Guard executed before any content leaves the system: outbound payloads
     * are only allowed when `allow_external_payload` is explicitly true.
     *
     * A null / 0 / 'false' value (including a raw row inserted without the
     * column) fails closed with ExternalPayloadDisabledException.
     *
     * @throws ExternalPayloadDisabledException
     */
    public function assertPayloadPolicy(): void
    {
        if ($this->allowsExternalPayload()) {
            return;
        }

        throw new ExternalPayloadDisabledException;
    }

    /**
     * Whitelist test for `allow_external_payload`, read from the value as it
     * was loaded/set (never the boolean cast: `(bool) 'false'` is true).
     *
     * Uses `getRawOriginal()` because the `boolean` cast rewrites the raw
     * attribute on assignment/hydration (`'false'` → true); the value is
     * therefore read from the row as the database holds it.
     *
     * Accepted: true, 1, '1', 'true', 'on', 'yes'. Rejected: null, 0, '0',
     * 'false', '' (spec §7.8 - the guard fails closed).
     */
    private function allowsExternalPayload(): bool
    {
        // Prefer the value as stored in the database; fall back to the
        // attribute being set when the model has not been persisted yet.
        $raw = $this->getRawOriginal('allow_external_payload');
        $value = $raw !== null ? $raw : $this->getAttributes()['allow_external_payload'] ?? null;

        // `true` and the storage values MySQL uses for a boolean column.
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }

        if (! is_scalar($value)) {
            return false;
        }

        // Only the explicit affirmative spellings are accepted; anything else
        // (`'false'`, `'0'`, `''`, `null`) fails closed.
        return in_array(strtolower(trim((string) $value)), ['true', 'on', 'yes'], true);
    }

    protected static function booted(): void
    {
        static::saving(function (self $config): void {
            if ($config->writingRevision || ! $config->needsRevision()) {
                return;
            }

            $config->writeRevision();
        });
    }

    /**
     * Whether the pending changes must produce a new revision row instead of
     * an in-place update.
     */
    private function needsRevision(): bool
    {
        // Brand new rows and revision inserts are their own snapshot.
        if ($this->writingRevision || ! $this->exists || ! $this->getOriginal('is_active')) {
            return false;
        }

        $dirty = array_keys($this->getDirty());
        $immutable = array_diff($dirty, ['is_active', 'updated_at']);

        return array_intersect($immutable, self::IMMUTABLE_COLUMNS) !== [];
    }

    /**
     * Deactivate the current row and insert the revision, then point this
     * instance at the new row so the caller sees the persisted state.
     */
    private function writeRevision(): void
    {
        $connection = $this->getConnection();

        $revisionId = $connection->transaction(function () use ($connection): int {
            static::query()
                ->whereKey($this->getKey())
                ->update(['is_active' => false, 'updated_at' => now()]);

            $revision = new static;
            $revision->setConnection($connection->getName());

            foreach ($this->getAttributes() as $column => $value) {
                if (in_array($column, ['id', 'created_at', 'updated_at', 'is_active'], true)) {
                    continue;
                }

                $revision->setAttribute($column, $value);
            }

            // Apply cached casts so dirty tracking stays consistent.
            $revision->setRawAttributes(
                array_merge($revision->getAttributes(), ['is_active' => true]),
                true
            );

            $revision->writingRevision = true;
            $revision->save();
            $revision->writingRevision = false;

            return (int) $revision->getKey();
        });

        $fresh = static::query()->findOrFail($revisionId);

        $this->setRawAttributes($fresh->getAttributes(), true);
        $this->exists = true;
        $this->wasRecentlyCreated = $fresh->wasRecentlyCreated;
    }
}
