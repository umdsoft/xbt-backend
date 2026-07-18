<?php

declare(strict_types=1);

namespace App\Domains\Hr\Enums;

enum AppealPriority: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';
    case URGENT = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Паст',
            self::NORMAL => 'Оддий',
            self::HIGH => 'Юқори',
            self::URGENT => 'Шошилинч',
        };
    }

    public static function values(): string
    {
        return implode(',', array_column(self::cases(), 'value'));
    }
}
