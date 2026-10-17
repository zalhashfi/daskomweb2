<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class SubmissionUnlock
 *
 * Audit trail of a jawaban submission unlocked manually by a kadiv.
 *
 * @property int $id
 * @property int $submission_id
 * @property int $unlocked_by_asisten_id
 * @property string $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property JawabanSubmission $submission
 * @property Asisten $unlockedBy
 */
class SubmissionUnlock extends Model
{
    protected $table = 'submission_unlocks';

    protected $fillable = [
        'submission_id',
        'unlocked_by_asisten_id',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submission_id' => 'integer',
            'unlocked_by_asisten_id' => 'integer',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(JawabanSubmission::class, 'submission_id');
    }

    public function unlockedBy(): BelongsTo
    {
        return $this->belongsTo(Asisten::class, 'unlocked_by_asisten_id');
    }
}
