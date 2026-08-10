<?php

declare(strict_types=1);

namespace App\Domains\Sport\Support;

/**
 * O'zbek kirill → lotin transliteratsiyasi (ko'rsatish uchun).
 *
 * Trener matnlari (F.I.Sh, ish joyi, maktab) manbada kirill; sahifa lotin
 * bo'lishi kerak. Mahalla/tuman nomlari uchun master.name_lat afzal (rasmiy),
 * bu esa qolgan erkin matnlar uchun mexanik o'girish.
 */
final class Translit
{
    /** @var array<string, string> ko'p belgili birikmalar OLDIN (strtr eng uzun mosni oladi). */
    private const MAP = [
        'ў' => "o'", 'Ў' => "O'", 'қ' => 'q', 'Қ' => 'Q', 'ғ' => "g'", 'Ғ' => "G'",
        'ҳ' => 'h', 'Ҳ' => 'H', 'ч' => 'ch', 'Ч' => 'Ch', 'ш' => 'sh', 'Ш' => 'Sh',
        'щ' => 'sh', 'Щ' => 'Sh', 'я' => 'ya', 'Я' => 'Ya', 'ю' => 'yu', 'Ю' => 'Yu',
        'ё' => 'yo', 'Ё' => 'Yo', 'ж' => 'j', 'Ж' => 'J', 'х' => 'x', 'Х' => 'X',
        'ц' => 'ts', 'Ц' => 'Ts', 'ъ' => "'", 'Ъ' => "'", 'ь' => '', 'Ь' => '',
        'а' => 'a', 'А' => 'A', 'б' => 'b', 'Б' => 'B', 'в' => 'v', 'В' => 'V',
        'г' => 'g', 'Г' => 'G', 'д' => 'd', 'Д' => 'D', 'е' => 'e', 'Е' => 'E',
        'з' => 'z', 'З' => 'Z', 'и' => 'i', 'И' => 'I', 'й' => 'y', 'Й' => 'Y',
        'к' => 'k', 'К' => 'K', 'л' => 'l', 'Л' => 'L', 'м' => 'm', 'М' => 'M',
        'н' => 'n', 'Н' => 'N', 'о' => 'o', 'О' => 'O', 'п' => 'p', 'П' => 'P',
        'р' => 'r', 'Р' => 'R', 'с' => 's', 'С' => 'S', 'т' => 't', 'Т' => 'T',
        'у' => 'u', 'У' => 'U', 'ф' => 'f', 'Ф' => 'F', 'ы' => 'i', 'Ы' => 'I',
        'э' => 'e', 'Э' => 'E',
    ];

    public static function toLatin(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return $s;
        }

        // Agar allaqachon kirill emas (lotin) bo'lsa — o'zgartirmaymiz.
        if (! preg_match('/\p{Cyrillic}/u', $s)) {
            return $s;
        }

        return strtr($s, self::MAP);
    }
}
