<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class JawabanSubmission
 *
 * @property int $id
 * @property int $praktikan_id
 * @property int $modul_id
 * @property string $tipe
 * @property int $attempt_no
 * @property int|null $jawaban_id
 * @property Carbon|null $locked_at
 * @property string|null $locked_by_type
 * @property int|null $locked_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Praktikan $praktikan
 * @property Modul $modul
 */
class JawabanSubmission extends Model
{
    public const TIPE_TP = 'tp';

    public const TIPE_TM = 'tm';

    /**
     * Tipe => the answer table/model that `jawaban_id` points into.
     *
     * @var array<string, class-string<Model>>
     */
    public const JAWABAN_MODELS = [
        self::TIPE_TP => JawabanTp::class,
        self::TIPE_TM => JawabanMandiri::class,
    ];

    protected $table = 'jawaban_submissions';

    protected $fillable = [
        'praktikan_id',
        'modul_id',
        'tipe',
        'attempt_no',
        'jawaban_id',
        'locked_at',
        'locked_by_type',
        'locked_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'praktikan_id' => 'integer',
            'modul_id' => 'integer',
            'attempt_no' => 'integer',
            'jawaban_id' => 'integer',
            'locked_at' => 'datetime',
            'locked_by_id' => 'integer',
        ];
    }

    public function praktikan(): BelongsTo
    {
        return $this->belongsTo(Praktikan::class, 'praktikan_id');
    }

    public function modul(): BelongsTo
    {
        return $this->belongsTo(Modul::class, 'modul_id');
    }

    /**
     * Resolve the underlying answer row.
     *
     * A polymorphic morphTo() is deliberately NOT used here: jawaban_tps and
     * jawaban_mandiris have no shared morph type column, so morphTo() would
     * require an extra `jawaban_type` column and a global morphMap. Instead the
     * lookup is deterministic and driven by the `tipe` column:
     *   - 'tp' => JawabanTp (jawaban_tps)
     *   - 'tm' => JawabanMandiri (jawaban_mandiris)
     * Any other tipe, or a null `jawaban_id`, resolves to null.
     *
     * Because this is a plain method (not a relation), call it explicitly as
     * `$submission->jawaban()`; `$submission->jawaban` is not available.
     */
    public function jawaban(): ?Model
    {
        if ($this->jawaban_id === null) {
            return null;
        }

        $modelClass = self::JAWABAN_MODELS[$this->tipe] ?? null;

        if ($modelClass === null) {
            return null;
        }

        return $modelClass::query()->find($this->jawaban_id);
    }

    /**
     * @param  Builder<JawabanSubmission>  $query
     * @return Builder<JawabanSubmission>
     */
    public function scopeForModul(Builder $query, int $modulId): Builder
    {
        return $query->where('modul_id', $modulId);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
