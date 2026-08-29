<?php

declare(strict_types=1);

namespace Tests\Feature\Ayollar;

use App\Domains\Ayollar\Models\Anketa;

/**
 * API testlari uchun qo'shimcha fikstura yordamchilari.
 *
 * `AyollarTestCase` dan ALOHIDA: unda domen fiksturalari (ayol, anketa),
 * bu yerda esa HTTP testiga xos qulayliklar. Ikkisini bitta klassga
 * tiqish uni yuzlab qatorli «hamma narsa» bazasiga aylantirardi.
 */
abstract class AyollarApiTestCase extends AyollarTestCase
{
    /** @return array{0: string, 1: string, 2: string} [o'z MFY, begona MFY, tuman] */
    protected function twoMahallas(): array
    {
        $district = $this->someDistrictId();
        $own = $this->someMahallaId($district);

        return [$own, $this->otherMahallaId($own, $district), $district];
    }

    /**
     * MFY'da bitta anketa yaratadi.
     *
     * @param  array<string, mixed>|null  $answers
     */
    protected function anketaIn(
        string $mahallaId,
        string $districtId,
        ?array $answers = null,
        ?string $pinfl = null,
        int $age = 30,
    ): Anketa {
        $household = $this->makeHousehold($mahallaId, $districtId);
        $woman = $this->makeWoman($household, $age, $pinfl);

        return $this->makeAnketa($woman, $answers ?? [
            'q11' => 'rasmiy_davlat', 'q12' => 'yoq', 'q13' => 'yoq',
        ]);
    }
}
