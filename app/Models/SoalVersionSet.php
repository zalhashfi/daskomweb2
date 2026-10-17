<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class SoalVersionSet
 *
 * A versioned snapshot of one modul's questions. Only one set per modul is
 * "active" at a time: the row whose activated_at is not null.
 *
 * @property int $id
 * @property int $modul_id
 * @property int $version_no
 * @property string $status
 * @property int|null $created_by_asisten_id
 * @property string|null $approved_by_type
 * @property int|null $approved_by_id
 * @property int $bobot_total
 * @property Carbon|null $activated_at
 * @property string|null $reject_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Modul $modul
 * @property-read Collection<int, SoalVersionItem> $items
 */
class SoalVersionSet extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'soal_version_sets';

    protected $fillable = [
        'modul_id',
        'version_no',
        'status',
        'created_by_asisten_id',
        'approved_by_type',
        'approved_by_id',
        'bobot_total',
        'activated_at',
        'reject_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modul_id' => 'integer',
            'version_no' => 'integer',
            'created_by_asisten_id' => 'integer',
            'approved_by_id' => 'integer',
            'bobot_total' => 'integer',
            'activated_at' => 'datetime',
        ];
    }

    public function modul(): BelongsTo
    {
        return $this->belongsTo(Modul::class, 'modul_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SoalVersionItem::class, 'version_set_id');
    }

    /**
     * A set is active only once it has been activated (activated_at not null).
     *
     * @param  Builder<SoalVersionSet>  $query
     * @return Builder<SoalVersionSet>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('activated_at');
    }

    /**
     * @param  Builder<SoalVersionSet>  $query
     * @return Builder<SoalVersionSet>
     */
    public function scopeForModul(Builder $query, int $modulId): Builder
    {
        return $query->where('modul_id', $modulId);
    }

    public function isActive(): bool
    {
        return $this->activated_at !== null;
    }
}
