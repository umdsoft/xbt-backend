<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Support;

/**
 * mahalla domeni uchun foydalanuvchi geo ko'lami (RBAC). Markaziy identifikatsiyadan
 * ajratilgan qiymat-obyekt: rol + tuman/mahalla/ko'chalar.
 */
final class MahallaScope
{
    /**
     * @param  array<int, string>  $streetIds
     */
    public function __construct(
        public readonly bool $isAdmin,
        public readonly ?string $districtId,
        public readonly ?string $mahallaId,
        public readonly array $streetIds,
        public readonly bool $restrictToStreets,
    ) {
    }
}
