<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Support;

/**
 * Lotin -> kirill (ko'rsatish uchun) va normalizatsiya (qidiruv uchun).
 *
 * NEGA FAQAT BIR YO'NALISH ASOSIY: o'zbek lotin->kirill deyarli deterministik
 * (sh->ш, o'->ў), teskarisi esa emas (ц -> ts/s). Shuning uchun lotin
 * YAGONA MANBA, kirill undan hosil qilinadi.
 *
 * Tartib muhim: ko'p harfli birikmalar (sh, ch, yo) bir harflilardan OLDIN.
 */
class Translit
{
    /** @var array<string, string> */
    private const LAT_TO_CYR = [
        'shch' => 'щ', 'Shch' => 'Щ', 'SHCH' => 'Щ',
        'yo' => 'ё', 'Yo' => 'Ё', 'YO' => 'Ё',
        'yu' => 'ю', 'Yu' => 'Ю', 'YU' => 'Ю',
        'ya' => 'я', 'Ya' => 'Я', 'YA' => 'Я',
        'ye' => 'е', 'Ye' => 'Е', 'YE' => 'Е',
        'ch' => 'ч', 'Ch' => 'Ч', 'CH' => 'Ч',
        'sh' => 'ш', 'Sh' => 'Ш', 'SH' => 'Ш',
        'ng' => 'нг', 'Ng' => 'Нг',
        'o‘' => 'ў', 'O‘' => 'Ў', "o'" => 'ў', "O'" => 'Ў',
        'g‘' => 'ғ', 'G‘' => 'Ғ', "g'" => 'ғ', "G'" => 'Ғ',
        'ts' => 'ц', 'Ts' => 'Ц',
        'a' => 'а', 'b' => 'б', 'd' => 'д', 'e' => 'е', 'f' => 'ф', 'g' => 'г',
        'h' => 'ҳ', 'i' => 'и', 'j' => 'ж', 'k' => 'к', 'l' => 'л', 'm' => 'м',
        'n' => 'н', 'o' => 'о', 'p' => 'п', 'q' => 'қ', 'r' => 'р', 's' => 'с',
        't' => 'т', 'u' => 'у', 'v' => 'в', 'x' => 'х', 'y' => 'й', 'z' => 'з',
        'A' => 'А', 'B' => 'Б', 'D' => 'Д', 'E' => 'Е', 'F' => 'Ф', 'G' => 'Г',
        'H' => 'Ҳ', 'I' => 'И', 'J' => 'Ж', 'K' => 'К', 'L' => 'Л', 'M' => 'М',
        'N' => 'Н', 'O' => 'О', 'P' => 'П', 'Q' => 'Қ', 'R' => 'Р', 'S' => 'С',
        'T' => 'Т', 'U' => 'У', 'V' => 'В', 'X' => 'Х', 'Y' => 'Й', 'Z' => 'З',
        'ʼ' => 'ъ', '‘' => 'ъ', "'" => 'ъ',
    ];

    /** @var array<string, string> Kirill -> lotin: faqat qidiruvni normallashtirish uchun. */
    private const CYR_TO_LAT = [
        'ё' => 'yo', 'ю' => 'yu', 'я' => 'ya', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ў' => "o'", 'ғ' => "g'", 'қ' => 'q', 'ҳ' => 'h', 'ц' => 'ts', 'ъ' => "'", 'ь' => '',
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
        'ж' => 'j', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l',
        'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
        'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'x', 'ы' => 'i', 'э' => 'e',
    ];

    public static function toCyr(string $lat): string
    {
        return strtr($lat, self::LAT_TO_CYR);
    }

    public static function toLat(string $cyr): string
    {
        return strtr(mb_strtolower($cyr), self::CYR_TO_LAT);
    }

    /**
     * Qidiruv kaliti: kirill bo'lsa lotinga o'giriladi, apostrof/qo'sh bo'shliq
     * tozalanadi, UPPER qilinadi. `youth.full_name_norm` shu shaklda saqlanadi.
     */
    public static function normalize(string $text): string
    {
        if (preg_match('/\p{Cyrillic}/u', $text) === 1) {
            $text = self::toLat($text);
        }

        $text = str_replace(['‘', '’', 'ʼ', "'"], '', $text);
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        return mb_strtoupper($text);
    }
}
