<?php

declare(strict_types=1);

namespace App\Domains\Hr\Enums;

/**
 * Топшириқ манбаси: назорат режа ичида ёки мустақил.
 */
enum TaskSource: string
{
    case CONTROL_PLAN = 'control_plan';
    case STANDALONE = 'standalone';

    public function label(): string
    {
        return match ($this) {
            self::CONTROL_PLAN => 'Назорат режа',
            self::STANDALONE => 'Мустақил топшириқ',
        };
    }

    /** Validatsiya uchun: 'control_plan,standalone' */
    public static function values(): string
    {
        return implode(',', array_column(self::cases(), 'value'));
    }
}
