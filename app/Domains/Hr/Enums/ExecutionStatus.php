<?php

declare(strict_types=1);

namespace App\Domains\Hr\Enums;

enum ExecutionStatus: string
{
    case NOT_STARTED = 'not_started';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case OVERDUE = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'Бажарилмаган',
            self::IN_PROGRESS => 'Бажарилмоқда',
            self::COMPLETED => 'Бажарилган',
            self::OVERDUE => 'Муддати ўтган',
        };
    }

    /** Validatsiya uchun: 'not_started,in_progress,completed,overdue' */
    public static function values(): string
    {
        return implode(',', array_column(self::cases(), 'value'));
    }

    /** Muddati o'tgan deb hisoblash mumkin bo'lgan holatlar */
    public static function pendingStatuses(): array
    {
        return [self::NOT_STARTED->value, self::IN_PROGRESS->value];
    }
}
