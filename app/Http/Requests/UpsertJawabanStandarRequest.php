<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Body for PUT /api-v1/soal-{tp|tm}/{idSoal}/jawaban-standar.
 *
 * - jawaban_standar : required, at least 1 character (empty kunci is not allowed).
 * - bobot           : optional integer 0..100.
 * - max_score       : optional integer >= 1.
 */
class UpsertJawabanStandarRequest extends FormRequest
{
    /**
     * Authorization is enforced by the route middleware
     * (auth:asisten + permission:manage-soal).
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
            'jawaban_standar' => ['required', 'string', 'min:1'],
            'bobot' => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_score' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jawaban_standar.required' => 'Jawaban standar wajib diisi.',
            'jawaban_standar.min' => 'Jawaban standar minimal 1 karakter.',
            'bobot.integer' => 'Bobot harus berupa bilangan bulat.',
            'bobot.min' => 'Bobot minimal 0.',
            'bobot.max' => 'Bobot maksimal 100.',
            'max_score.integer' => 'Max score harus berupa bilangan bulat.',
            'max_score.min' => 'Max score minimal 1.',
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
