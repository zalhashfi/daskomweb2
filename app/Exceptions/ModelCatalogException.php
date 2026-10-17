<?php

namespace App\Exceptions;

/**
 * Raised by App\Services\Ai\ModelCatalog when a model id is absent from the
 * gateway catalogue (`MODEL_NOT_FOUND`) or is a Combo model routing to
 * several providers (`COMBO_NOT_ALLOWED`, spec D21 / E29).
 */
class ModelCatalogException extends AiUnavailableException
{
    public const ERROR_CODE_MODEL_NOT_FOUND = 'MODEL_NOT_FOUND';

    public const ERROR_CODE_COMBO_NOT_ALLOWED = 'COMBO_NOT_ALLOWED';

    public static function modelNotFound(string $id): self
    {
        return new self(
            self::ERROR_CODE_MODEL_NOT_FOUND,
            'Model "'.$id.'" tidak ditemukan di katalog provider (/v1/models).',
        );
    }

    public static function comboNotAllowed(string $id): self
    {
        return new self(
            self::ERROR_CODE_COMBO_NOT_ALLOWED,
            'Model "'.$id.'" adalah Combo provider yang merutekan ke beberapa provider; Combo tidak diizinkan.',
        );
    }
}
