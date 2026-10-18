<?php

namespace App\Http\Requests;

use App\Enums\AiRegradePriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * JSON body for POST /api-v1/ai-regrade.
 *
 * - modul_id : required, must exist in `moduls`
 * - priority : optional, `high` | `normal` (default `normal`)
 */
class AiRegradeRequest extends FormRequest
{
    /**
     * Authorization is enforced by the route middleware
     * (auth:asisten + permission:regrade-ai).
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
            'modul_id' => ['required', 'integer', 'exists:moduls,id'],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(AiRegradePriority::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'modul_id.required' => 'modul_id wajib diisi.',
            'modul_id.exists' => 'Modul tidak ditemukan.',
            'priority.in' => 'priority harus salah satu dari: '.implode(', ', AiRegradePriority::values()).'.',
        ];
    }

    public function modulId(): int
    {
        return (int) $this->validated('modul_id');
    }

    public function priority(): AiRegradePriority
    {
        $priority = $this->validated('priority');

        return AiRegradePriority::fromRequest(is_string($priority) ? $priority : null);
    }
}
