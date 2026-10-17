<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * JSON body for POST /api-v1/modul-documents/{id}/reject.
 *
 * - reason : required, at least 5 characters (stored in reject_reason).
 */
class RejectModulDocumentRequest extends FormRequest
{
    public const REASON_MIN_LENGTH = 5;

    public const REASON_MAX_LENGTH = 2000;

    /**
     * Authorization is enforced by the route middleware
     * (auth:asisten + permission:approve-modul-documents).
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
            'reason' => ['required', 'string', 'min:'.self::REASON_MIN_LENGTH, 'max:'.self::REASON_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan penolakan wajib diisi.',
            'reason.string' => 'Alasan penolakan harus berupa teks.',
            'reason.min' => 'Alasan penolakan minimal '.self::REASON_MIN_LENGTH.' karakter.',
            'reason.max' => 'Alasan penolakan maksimal '.self::REASON_MAX_LENGTH.' karakter.',
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
