<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * JSON body for POST /api-v1/ai-provider-credentials and
 * POST /api-v1/ai-provider-credentials/test.
 *
 * - base_url : required, absolute http(s) URL
 * - api_key  : required, at least 10 characters
 * - reason   : required, at least 10 characters
 */
class SaveAiProviderCredentialRequest extends FormRequest
{
    public const API_KEY_MIN_LENGTH = 10;

    public const API_KEY_MAX_LENGTH = 512;

    public const REASON_MIN_LENGTH = 10;

    public const REASON_MAX_LENGTH = 1000;

    /**
     * Authorization is enforced by the route middleware
     * (auth:asisten + permission:manage-ai-gate / permission:view-ai-metrics).
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
            'base_url' => ['required', 'string', 'max:255', 'url:http,https'],
            'api_key' => ['required', 'string', 'min:'.self::API_KEY_MIN_LENGTH, 'max:'.self::API_KEY_MAX_LENGTH],
            'reason' => ['required', 'string', 'min:'.self::REASON_MIN_LENGTH, 'max:'.self::REASON_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'base_url.required' => 'Base URL provider AI wajib diisi.',
            'base_url.string' => 'Base URL provider AI harus berupa teks.',
            'base_url.max' => 'Base URL provider AI maksimal 255 karakter.',
            'base_url.url' => 'Base URL provider AI harus berupa URL valid dengan skema http atau https.',
            'api_key.required' => 'API key provider AI wajib diisi.',
            'api_key.string' => 'API key provider AI harus berupa teks.',
            'api_key.min' => 'API key provider AI minimal '.self::API_KEY_MIN_LENGTH.' karakter.',
            'api_key.max' => 'API key provider AI maksimal '.self::API_KEY_MAX_LENGTH.' karakter.',
            'reason.required' => 'Alasan perubahan kredensial provider AI wajib diisi.',
            'reason.string' => 'Alasan perubahan kredensial provider AI harus berupa teks.',
            'reason.min' => 'Alasan perubahan kredensial provider AI minimal '.self::REASON_MIN_LENGTH.' karakter.',
            'reason.max' => 'Alasan perubahan kredensial provider AI maksimal '.self::REASON_MAX_LENGTH.' karakter.',
        ];
    }
}
