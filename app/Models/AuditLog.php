<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'asisten_id',
        'action',
        'route',
        'method',
        'description',
        'subject_type',
        'subject_id',
        'actor_type',
        'actor_id',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'actor_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function asisten(): BelongsTo
    {
        return $this->belongsTo(Asisten::class);
    }
}
