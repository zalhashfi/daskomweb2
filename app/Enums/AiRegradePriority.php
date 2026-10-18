<?php

namespace App\Enums;

/**
 * Queue priority a batch regrade may ask for (`{"priority":"high"}`).
 *
 * The value is handed to `EvaluateAnswer` verbatim, so the job carries
 * `$priority === 'high'` and the queue worker can sort on it.
 */
enum AiRegradePriority: string
{
    case HIGH = 'high';
    case NORMAL = 'normal';

    /**
     * Priority requested by the caller; `normal` when none was sent.
     */
    public static function fromRequest(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::NORMAL;
        }

        return self::tryFrom($value) ?? self::NORMAL;
    }

    /**
     * Accepted values, for validation rules.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
