<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * JSON body for POST /api-v1/ai-gate/toggle.
 *
 * - ai_enabled : required boolean
 * - reason     : required, at least 10 characters
 */
class ToggleAiGateRequest extends FormRequest
{
    public const REASON_MIN_LENGTH = 10;

    public const REASON_MAX_LENGTH = 1000;

    /**
     * Authorization is enforced by the route middleware
     * (auth:asisten + permission:manage-ai-gate).
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
            'ai_enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:'.self::REASON_MIN_LENGTH, 'max:'.self::REASON_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ai_enabled.required' => 'Status ai_enabled wajib diisi.',
            'ai_enabled.boolean' => 'ai_enabled harus bernilai boolean.',
            'reason.required' => 'Alasan perubahan gate AI wajib diisi.',
            'reason.string' => 'Alasan perubahan gate AI harus berupa teks.',
            'reason.min' => 'Alasan perubahan gate AI minimal '.self::REASON_MIN_LENGTH.' karakter.',
            'reason.max' => 'Alasan perubahan gate AI maksimal '.self::REASON_MAX_LENGTH.' karakter.',
        ];
    }
}
