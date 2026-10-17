<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Multipart body for POST /api-v1/modul/{id}/documents.
 *
 * - file : required, PDF or DOCX, at most 20 MB (20480 KB).
 */
class UploadModulDocumentRequest extends FormRequest
{
    public const MAX_SIZE_KB = 20480;

    public const ALLOWED_MIMES = ['pdf', 'docx'];

    /**
     * Authorization is enforced by the route middleware
     * (auth:asisten + permission:manage-modul-documents).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', self::ALLOWED_MIMES),
                'max:'.self::MAX_SIZE_KB,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'File dokumen wajib diunggah.',
            'file.file' => 'Dokumen harus berupa file.',
            'file.mimes' => 'Dokumen harus berformat PDF atau DOCX.',
            'file.uploaded' => 'Dokumen gagal diunggah.',
            'file.max' => 'Ukuran dokumen maksimal 20 MB.',
        ];
    }

    /**
     * Keep the API error shape used across the API controllers.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'code' => 'VALIDATION_ERROR',
            'message' => 'Data yang dikirim tidak valid.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
