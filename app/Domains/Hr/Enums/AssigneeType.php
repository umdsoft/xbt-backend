<?php

declare(strict_types=1);

namespace App\Domains\Hr\Enums;

/**
 * Топшириқ масъули тури: ички ходим ёки ташкилот.
 */
enum AssigneeType: string
{
    case USER = 'user';
    case ORGANIZATION = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::USER => 'Ички ходим',
            self::ORGANIZATION => 'Ташкилот',
        };
    }

    /** Validatsiya uchun: 'user,organization' */
    public static function values(): string
    {
        return implode(',', array_column(self::cases(), 'value'));
    }
}
