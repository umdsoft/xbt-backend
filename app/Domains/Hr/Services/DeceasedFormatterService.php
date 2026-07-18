<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services;

/**
 * TT бўлим 3.5.1: Вафот этган қариндош иш жойи автоматик формати.
 * Формат: «{йил} йилда вафот этган ({аввалги лавозим})»
 *
 * Мисол: «1992 йилда вафот этган (1-марказий поликлиника шифокори)»
 */
class DeceasedFormatterService
{
    public function format(int $year, string $formerPosition): string
    {
        return "{$year} йилда вафот этган ({$formerPosition})";
    }
}
