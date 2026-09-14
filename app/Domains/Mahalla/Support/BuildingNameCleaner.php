<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Support;

/**
 * `master.buildings.purpose` (kadastr matni) dan KO'RSATISH uchun ism.
 *
 * Manba tartibsiz: tirnoqlar (`"`, `«`, `»`, `'`), ortiqcha bo'shliqlar,
 * BOSH HARFLAR aralash imlo bilan birga keladi (masalan: `"Шовот деҳқон
 * бозори"`, `ЙИЛКИЧИ БОБО КАБРИСТОНИ`). Bu klass faqat ENGIL kosmetika
 * qiladi — boshi/oxiridagi bo'shliq/tirnoqni olib tashlaydi va ketma-ket
 * bo'shliqlarni bittaga tushiradi.
 *
 * HARF REGISTRI ATAYLAB O'ZGARTIRILMAYDI: bu — kadastrning rasmiy yozuvi,
 * serverda pastga tushirilsa qaytarib bo'lmaydigan ma'lumot yo'qoladi.
 * Ko'rsatish uslubi (masalan Title Case) — mobil ilovaning ishi.
 */
final class BuildingNameCleaner
{
    /**
     * Qatorning ENG BOSHI/OXIRIDAGI bo'shliq va tirnoq belgilari ketma-
     * ketligi. Faqat chetlardan olib tashlanadi — matn ICHIDAGI tirnoq
     * (masalan `"Дукон"биноси`dagi kabi) tegilmay qoladi.
     */
    private const EDGE_PATTERN = '/^[\s"«»\']+|[\s"«»\']+$/u';

    /**
     * `purpose`ni ko'rsatish uchun tozalaydi. Natija bo'sh bo'lsa (yoki
     * kirish `null` bo'lsa) — `null`: chaqiruvchi tomon `category_label`ga
     * qaytishi mumkin bo'lgan aniq belgi.
     */
    public static function clean(?string $purpose): ?string
    {
        if ($purpose === null) {
            return null;
        }

        $trimmed = (string) preg_replace(self::EDGE_PATTERN, '', $purpose);
        $collapsed = (string) preg_replace('/\s+/u', ' ', $trimmed);

        return $collapsed === '' ? null : $collapsed;
    }
}
