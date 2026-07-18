<?php

declare(strict_types=1);

namespace App\Domains\Hr\Enums;

/**
 * Топшириқ ижросини тасдиқлаш ҳолати (EDO).
 *  - submitted: ташкилот ижрони тасдиққа юборди
 *  - approved: котибият мудири тасдиқлади
 *  - returned: котибият мудири қайта ишлашга қайтарди
 */
enum ReviewStatus: string
{
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case RETURNED = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::SUBMITTED => 'Тасдиқ кутилмоқда',
            self::APPROVED => 'Тасдиқланган',
            self::RETURNED => 'Қайта ишлашга қайтарилган',
        };
    }
}
