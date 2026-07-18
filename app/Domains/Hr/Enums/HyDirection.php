<?php

declare(strict_types=1);

namespace App\Domains\Hr\Enums;

enum HyDirection: string
{
    case IQTISODIYOT = 'iqtisodiyot';
    case QURILISH = 'qurilish';
    case QISHLOQ = 'qishloq';
    case IJTIMOIY = 'ijtimoiy';
    case MADANIYAT = 'madaniyat';
    case YOSHLAR = 'yoshlar';
    case BOSHQA = 'boshqa';

    public function label(): string
    {
        return match ($this) {
            self::IQTISODIYOT => 'Иқтисодиёт',
            self::QURILISH => 'Қурилиш',
            self::QISHLOQ => 'Қишлоқ',
            self::IJTIMOIY => 'Ижтимоий',
            self::MADANIYAT => 'Маданият',
            self::YOSHLAR => 'Ёшлар',
            self::BOSHQA => 'Бошқа',
        };
    }

    public static function values(): string
    {
        return implode(',', array_column(self::cases(), 'value'));
    }
}
