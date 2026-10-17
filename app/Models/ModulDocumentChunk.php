<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class ModulDocumentChunk
 *
 * One text chunk of a modul_document for a given index_version, optionally
 * carrying its embedding vector (stored as JSON).
 *
 * @property int $id
 * @property int $modul_document_id
 * @property int $index_version
 * @property int $chunk_index
 * @property string $content
 * @property array<int, float>|null $embedding
 * @property int|null $embedding_dimension
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ModulDocument|null $document
 */
class ModulDocumentChunk extends Model
{
    protected $table = 'modul_document_chunks';

    protected $fillable = [
        'modul_document_id',
        'index_version',
        'chunk_index',
        'content',
        'embedding',
        'embedding_dimension',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modul_document_id' => 'integer',
            'index_version' => 'integer',
            'chunk_index' => 'integer',
            'embedding' => 'array',
            'embedding_dimension' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ModulDocument::class, 'modul_document_id');
    }
}
