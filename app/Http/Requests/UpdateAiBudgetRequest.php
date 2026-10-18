<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * JSON body for POST /api-v1/ai-gate/budget.
 *
 * - budget_tokens_per_modul : required integer >= 0 (0 = unlimited)
 * - reason                  : required, at least 10 characters
 */
class UpdateAiBudgetRequest extends FormRequest
{
    public const REASON_MIN_LENGTH = 10;

    public const REASON_MAX_LENGTH = 1000;

    /**
     * Authorization is enforced by the route middleware
     * (auth:asisten + permission:manage-ai-budget).
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
            'budget_tokens_per_modul' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'reason' => ['required', 'string', 'min:'.self::REASON_MIN_LENGTH, 'max:'.self::REASON_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'budget_tokens_per_modul.required' => 'Budget token per modul wajib diisi.',
            'budget_tokens_per_modul.integer' => 'Budget token per modul harus berupa bilangan bulat.',
            'budget_tokens_per_modul.min' => 'Budget token per modul tidak boleh negatif.',
            'reason.required' => 'Alasan perubahan budget AI wajib diisi.',
            'reason.string' => 'Alasan perubahan budget AI harus berupa teks.',
            'reason.min' => 'Alasan perubahan budget AI minimal '.self::REASON_MIN_LENGTH.' karakter.',
            'reason.max' => 'Alasan perubahan budget AI maksimal '.self::REASON_MAX_LENGTH.' karakter.',
        ];
    }
}
