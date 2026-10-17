<?php

namespace App\Models;

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
