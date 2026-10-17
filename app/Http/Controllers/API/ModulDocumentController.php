<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadModulDocumentRequest;
use App\Http\Resources\ModulDocumentResource;
use App\Models\Modul;
use App\Models\ModulDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Source documents (PDF/DOCX) attached to a modul.
 *
 * POST /api-v1/modul/{id}/documents (multipart `file`)
 *
 * - 404 : no such modul
 * - 422 : file missing, not PDF/DOCX, or larger than 20 MB
 * - 202 : stored on the `local` disk under modul-documents/ and recorded in
 *         modul_documents with status `draft`
 */
class ModulDocumentController extends Controller
{
    public const DISK = 'local';

    public const DIRECTORY = 'modul-documents';

    public function store(UploadModulDocumentRequest $request, int $modulId): JsonResponse
    {
        if (! Modul::query()->whereKey($modulId)->exists()) {
            return response()->json([
                'status' => 'error',
                'code' => 'NOT_FOUND',
                'message' => "Modul #{$modulId} tidak ditemukan.",
            ], 404);
        }

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $path = $file->store(self::DIRECTORY, self::DISK);

        $document = DB::transaction(fn (): ModulDocument => ModulDocument::create([
            'modul_id' => $modulId,
            'uploaded_by_asisten_id' => (int) $request->user('asisten')->getKey(),
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'checksum_sha256' => hash_file('sha256', Storage::disk(self::DISK)->path($path)),
            'status' => ModulDocument::STATUS_DRAFT,
        ]));

        return response()->json([
            'status' => 'success',
            'message' => 'Dokumen berhasil diunggah.',
            'data' => new ModulDocumentResource($document),
        ], 202);
    }
}
