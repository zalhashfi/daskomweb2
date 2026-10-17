<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a modul_documents row.
 *
 * POST /api-v1/modul/{id}/documents
 */
class ModulDocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'modul_id' => $this->modul_id,
            'uploaded_by_asisten_id' => $this->uploaded_by_asisten_id,
            'original_name' => $this->original_name,
            'path' => $this->path,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'checksum_sha256' => $this->checksum_sha256,
            'status' => $this->status,
            'index_version' => $this->index_version,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
