<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Support;

use App\Domains\Qurilish\Models\Organization;
use App\Domains\Qurilish\Models\OrganizationAlias;

/**
 * Tashkilot nomlarini kanonik reyestrga keltiradi.
 *
 * Manbadagi muammo: bitta tashkilot 5-6 xil yozilgan —
 *   `"XORAZM SUV LOYIHA" MCHJ` / `XORAZM SUV LOYIHA MCHJ` /
 *   `"XORAZM SUV LOYIHA" mas’uliyati cheklangan jamiyati`
 * Xom holda 106 loyihachi va 240 pudratchi ko'rinadi; normalizatsiyadan keyin
 * ~60 va ~200 qoladi.
 *
 * Ikki bosqichli yechim:
 *   1) MEXANIK normalizatsiya — tirnoq/bo'shliq/punktuatsiya olib tashlanadi,
 *      huquqiy shakl qisqartmaga keltiriladi, kirill lotinga o'giriladi.
 *      Bu formatlash farqlarini yopadi.
 *   2) QO'LDA aniqlangan imlo xatolari — `SPELLING_FIXES`. Mexanik qoida
 *      «буюрмачи» va «буюртмачи» ni birlashtira olmaydi (bu haqiqiy typo,
 *      formatlash emas), shuning uchun ular aniq ro'yxatda. Har biri manba
 *      tahlilida sanoq bilan tasdiqlangan (СВОД ЗАКАЗЧИК jamlanmasiga mos).
 */
class OrgRegistry
{
    /**
     * Huquqiy shakl -> qisqartma. Uzunroq ibora OLDIN turishi shart.
     *
     * @var array<string, string>
     */
    private const LEGAL_FORMS = [
        'MASULIYATICHEKLANGANJAMIYATI' => 'MCHJ',
        'MASULIYATICHEKLANGANJAMIYAT' => 'MCHJ',
        'XUSUSIYKORXONASI' => 'XK',
        'XUSUSIYKORXONA' => 'XK',
        'AKSIYADORLIKJAMIYATI' => 'AJ',
        'DAVLATUNITARKORXONASI' => 'DUK',
        'DAVLATMUASSASASI' => 'DM',
        'MCHJ' => 'MCHJ',
    ];

    /**
     * Manbadagi imlo xatolari -> kanonik normallashtirilgan kalit.
     *
     * Chapdagi kalit — `normalize()` natijasi (xato variant),
     * o'ngdagi — kanonik variantning `normalize()` natijasi.
     *
     * @var array<string, string>
     */
    private const SPELLING_FIXES = [
        // «Ягона буюрмачи хизмати» (205 obyekt) -> «Ягона буюртмачи хизмати» (124).
        'YAGONABUYURMACHIXIZMATIDM' => 'YAGONABUYURTMACHIXIZMATIDM',
        // Hududiy prefiks va huquqiy shakl farqi: 82 + 37 + 4 = 123 (СВОД bilan mos).
        'XORAZMMINTAQAVIYYOLLARGABUYURTMACHIXIZMATIDM' => 'MINTAQAVIYYOLLARGABUYURTMACHIXIZMATIDM',
        'MINTAQAVIYYOLLARGABUYURTMACHIXIZMATIDUK' => 'MINTAQAVIYYOLLARGABUYURTMACHIXIZMATIDM',
        // «Худудий электр тармоклари» (қ/ҳ siz) -> «Ҳудудий электр тармоқлари»: 12 + 5 = 17.
        'XUDUDIYELEKTRTARMOKLARIAJ' => 'HUDUDIYELEKTRTARMOQLARIAJ',
        'HUDUDIYELEKTRTARMOQLARIAJ' => 'HUDUDIYELEKTRTARMOQLARIAJ',
        // «Хоразм сув курилиш инвест» (қ siz) -> «...қурилиш...»: 20 + 4 = 24.
        'XORAZMSUVKURILISHINVESTDM' => 'XORAZMSUVQURILISHINVESTDM',
    ];

    /** @var array<string, string> alias_norm -> organization uuid (so'rov davomida kesh) */
    private array $cache = [];

    /**
     * Xom nomni tashkilot id siga aylantiradi; kerak bo'lsa yangisini yaratadi.
     *
     * @param  string  $roleFlag  is_customer|is_designer|is_contractor|is_department
     */
    public function resolve(?string $raw, string $roleFlag): ?string
    {
        $raw = $raw === null ? '' : trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        if ($raw === '') {
            return null;
        }

        $norm = $this->normalize($raw);
        if ($norm === '') {
            return null;
        }

        $norm = self::SPELLING_FIXES[$norm] ?? $norm;

        if (isset($this->cache[$norm])) {
            $this->flag($this->cache[$norm], $roleFlag);

            return $this->cache[$norm];
        }

        $alias = OrganizationAlias::query()->where('alias_norm', $norm)->first();
        if ($alias !== null) {
            $this->cache[$norm] = (string) $alias->organization_id;
            $this->rememberAlias($this->cache[$norm], $raw, $norm);
            $this->flag($this->cache[$norm], $roleFlag);

            return $this->cache[$norm];
        }

        $org = Organization::query()->create([
            'name_cyr' => $raw,
            'name_lat' => (string) Translit::toLatin($raw),
            $roleFlag => true,
            'is_active' => true,
        ]);

        $this->cache[$norm] = (string) $org->id;
        $this->rememberAlias($this->cache[$norm], $raw, $norm);

        return $this->cache[$norm];
    }

    /**
     * Solishtirish kaliti: lotin, faqat harf-raqam, huquqiy shakl qisqartirilgan.
     *
     * `"XORAZM SUV LOYIHA" mas’uliyati cheklangan jamiyati` -> `XORAZMSUVLOYIHAMCHJ`
     */
    public function normalize(string $raw): string
    {
        $s = (string) Translit::toLatin($raw);
        $s = mb_strtoupper($s);
        // Apostrof variantlari (ʻ ’ ` ') va tirnoqlar — solishtirishda ahamiyatsiz.
        $s = preg_replace('/[^A-Z0-9]/u', '', $s) ?? '';

        foreach (self::LEGAL_FORMS as $long => $short) {
            if ($long !== $short && str_ends_with($s, $long)) {
                $s = substr($s, 0, -strlen($long)).$short;
                break;
            }
        }

        return $s;
    }

    private function rememberAlias(string $orgId, string $raw, string $norm): void
    {
        OrganizationAlias::query()->firstOrCreate(
            ['alias_raw' => $raw],
            ['organization_id' => $orgId, 'alias_norm' => $norm, 'created_at' => now()],
        );
    }

    private function flag(string $orgId, string $roleFlag): void
    {
        Organization::query()->where('id', $orgId)->where($roleFlag, false)->update([$roleFlag => true]);
    }
}
