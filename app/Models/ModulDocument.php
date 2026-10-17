<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class ModulDocument
 *
 * An uploaded source document belonging to a modul, tracked through its
 * approval/indexing lifecycle:
 * draft -> approved -> indexing -> active (or failed/rejected).
 *
 * @property int $id
 * @property int $modul_id
 * @property int|null $uploaded_by_asisten_id
 * @property string $original_name
 * @property string $path
 * @property string $mime
 * @property int $size_bytes
 * @property string|null $checksum_sha256
 * @property string $status
 * @property string|null $reject_reason
 * @property int $index_version
 * @property int|null $approved_by_asisten_id
 * @property Carbon|null $approved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Modul|null $modul
 * @property-read Asisten|null $uploader
 */
class ModulDocument extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_INDEXING = 'indexing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_INDEXING,
        self::STATUS_ACTIVE,
        self::STATUS_FAILED,
    ];

    protected $table = 'modul_documents';

    protected $fillable = [
        'modul_id',
        'uploaded_by_asisten_id',
        'original_name',
        'path',
        'mime',
        'size_bytes',
        'checksum_sha256',
        'status',
        'reject_reason',
        'index_version',
        'approved_by_asisten_id',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modul_id' => 'integer',
            'uploaded_by_asisten_id' => 'integer',
            'size_bytes' => 'integer',
            'index_version' => 'integer',
            'approved_by_asisten_id' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function modul(): BelongsTo
    {
        return $this->belongsTo(Modul::class, 'modul_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Asisten::class, 'uploaded_by_asisten_id');
    }
}
