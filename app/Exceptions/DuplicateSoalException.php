<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Raised by App\Services\Jawaban\SubmissionWriter when one request carries the
 * same `soal_id` more than once.
 *
 * It extends ValidationException so the controller (and Laravel's default
 * handler) render it as a `422` with the usual `errors` bag, and nothing is
 * written because the check runs before the transaction starts.
 */
class DuplicateSoalException extends ValidationException
{
    /**
     * @param  list<int>  $duplicateSoalIds
     */
    public static function make(array $duplicateSoalIds, string $field = 'soal_id'): self
    {
        return self::withMessages([
            $field => 'Soal tidak boleh duplikat dalam satu permintaan: '.implode(', ', $duplicateSoalIds).'.',
        ]);
    }
}
