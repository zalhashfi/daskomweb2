<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class SoalVersionItem
 *
 * One question inside a soal_version_set: its bobot/max_score snapshot plus
 * the standard answer (kunci) state used by the dual-control approval flow.
 *
 * @property int $id
 * @property int $version_set_id
 * @property string $soal_type
 * @property int|null $soal_id
 * @property int $bobot
 * @property int $max_score
 * @property string|null $teks_snapshot
 * @property string|null $jawaban_standar
 * @property string $jawaban_standar_status
 * @property int|null $jawaban_standar_approved_by
 * @property Carbon|null $jawaban_standar_approved_at
 * @property string|null $reject_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SoalVersionSet $versionSet
 */
class SoalVersionItem extends Model
{
    public const SOAL_TP = 'tp';

    public const SOAL_TM = 'tm';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'soal_version_items';

    protected $fillable = [
        'version_set_id',
        'soal_type',
        'soal_id',
        'bobot',
        'max_score',
        'teks_snapshot',
        'jawaban_standar',
        'jawaban_standar_status',
        'jawaban_standar_approved_by',
        'jawaban_standar_approved_at',
        'reject_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_set_id' => 'integer',
            'soal_id' => 'integer',
            'bobot' => 'integer',
            'max_score' => 'integer',
            'jawaban_standar_approved_by' => 'integer',
            'jawaban_standar_approved_at' => 'datetime',
        ];
    }

    public function versionSet(): BelongsTo
    {
        return $this->belongsTo(SoalVersionSet::class, 'version_set_id');
    }
}
