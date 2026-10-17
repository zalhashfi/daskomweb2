<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectModulDocumentRequest;
use App\Http\Requests\UploadModulDocumentRequest;
use App\Http\Resources\ModulDocumentResource;
use App\Jobs\IngestModulDocument;
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
 *
 * POST /api-v1/modul-documents/{id}/approve
 *
 * - 404 : no such document
 * - 409 : document is not `draft`
 * - 202 : status `draft` -> `approved`, approved_by/approved_at set and an
 *         IngestModulDocument job queued
 *
 * POST /api-v1/modul-documents/{id}/reject (body `reason`, min 5 chars)
 *
 * - 404 : no such document
 * - 422 : `reason` missing or too short (nothing is changed)
 * - 409 : document is not `draft`
 * - 200 : status `draft` -> `rejected` with reject_reason
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

    public function approve(int $id): JsonResponse
    {
        $document = ModulDocument::query()->find($id);

        if ($document === null) {
            return $this->notFound($id);
        }

        if ($document->status !== ModulDocument::STATUS_DRAFT) {
            return $this->invalidStatus('disetujui');
        }

        $document->forceFill([
            'status' => ModulDocument::STATUS_APPROVED,
            'approved_by_asisten_id' => (int) auth('asisten')->id(),
            'approved_at' => now(),
            'reject_reason' => null,
        ])->save();

        IngestModulDocument::dispatch($document);

        return response()->json([
            'status' => 'success',
            'message' => 'Dokumen disetujui dan dijadwalkan untuk diindeks.',
            'data' => new ModulDocumentResource($document->refresh()),
        ], 202);
    }

    public function reject(RejectModulDocumentRequest $request, int $id): JsonResponse
    {
        $document = ModulDocument::query()->find($id);

        if ($document === null) {
            return $this->notFound($id);
        }

        if ($document->status !== ModulDocument::STATUS_DRAFT) {
            return $this->invalidStatus('ditolak');
        }

        $document->forceFill([
            'status' => ModulDocument::STATUS_REJECTED,
            'reject_reason' => (string) $request->validated('reason'),
        ])->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Dokumen ditolak.',
            'data' => new ModulDocumentResource($document->refresh()),
        ], 200);
    }

    private function notFound(int $id): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => 'NOT_FOUND',
            'message' => "Dokumen #{$id} tidak ditemukan.",
        ], 404);
    }

    private function invalidStatus(string $action): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => 'INVALID_STATUS',
            'message' => "Hanya dokumen berstatus draft yang dapat {$action}.",
        ], 409);
    }
}
