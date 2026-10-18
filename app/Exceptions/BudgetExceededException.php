<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised by App\Services\Ai\BudgetGuard::assertAvailable() when a modul has
 * already spent its `budget_tokens_per_modul` allowance for the given AI
 * config snapshot (`tokens_used >= budget_tokens_per_modul`).
 */
class BudgetExceededException extends RuntimeException
{
    public const ERROR_CODE = 'BUDGET_EXCEEDED';

    public function __construct(
        public readonly int $modulId,
        public readonly int $tokensUsed,
        public readonly int $budgetTokens,
        string $message = '',
    ) {
        if ($message === '') {
            $message = sprintf(
                'Kuota token AI untuk modul #%d telah habis (%d dari %d token).',
                $modulId,
                $tokensUsed,
                $budgetTokens,
            );
        }

        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }
}
