<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Support;

/**
 * O'zbek lotin yozuvidan kirillga o'girish (joy nomlari uchun).
 *
 * Kadastr manbasi (KMZ) mahalla nomlarini LOTIN yozuvida bergan, tizimning
 * qolgan hamma joyi esa kirillda. Rahbar ekranida aralash yozuv chiqmasligi
 * uchun nomlar bir marta o'girib qo'yiladi.
 *
 * Asl lotin yozuv `name_lat` ustunida saqlanadi, ya'ni o'girish qaytariladi.
 */
final class UzbekTransliterator
{
    /**
     * Apostrofning barcha ko'rinishlari. Manbada beshtasi uchraydi:
     * U+02BB (standart), U+02BC, U+2018, U+2019 va oddiy U+0027.
     */
    private const APOSTROPHES = ['ʻ', 'ʼ', '‘', '’', '`', "'"];

    /**
     * Ikki harfli birikmalar — bitta harfdan OLDIN qo'llanishi shart,
     * aks holda `sh` "с"+"ҳ" bo'lib ketadi.
     *
     * @var array<string, string>
     */
    private const DIGRAPHS = [
        'sh' => 'ш', 'ch' => 'ч', 'ya' => 'я', 'yo' => 'ё', 'yu' => 'ю',
        'ts' => 'ц',
    ];

    /** @var array<string, string> */
    private const LETTERS = [
        'a' => 'а', 'b' => 'б', 'd' => 'д', 'e' => 'е', 'f' => 'ф', 'g' => 'г',
        'h' => 'ҳ', 'i' => 'и', 'j' => 'ж', 'k' => 'к', 'l' => 'л', 'm' => 'м',
        'n' => 'н', 'o' => 'о', 'p' => 'п', 'q' => 'қ', 'r' => 'р', 's' => 'с',
        't' => 'т', 'u' => 'у', 'v' => 'в', 'x' => 'х', 'y' => 'й', 'z' => 'з',
        'c' => 'к',
    ];

    public static function toCyrillic(string $text): string
    {
        // Apostrof turlarini bittaga keltirib, ishlashni soddalashtiramiz.
        $text = str_replace(self::APOSTROPHES, "'", $text);

        $out = '';
        $len = mb_strlen($text);
        $i = 0;

        while ($i < $len) {
            $ch = mb_substr($text, $i, 1);
            $lower = mb_strtolower($ch);
            $isUpper = $ch !== $lower;

            // Yolg'iz apostrof — tutuq belgisi (ҚАЛЪА). Registri OLDINGI
            // harfdan olinadi: aks holda katta harfli nom ichida kichik "ъ"
            // paydo bo'lib, "ҚАЛъА" kabi g'alati ko'rinardi.
            if ($ch === "'") {
                $out .= self::isPrecededByUpper($out) ? 'Ъ' : 'ъ';
                $i++;

                continue;
            }

            // Kirill yoki boshqa belgi (raqam, tire, bo'shliq) — tegilmaydi.
            if (! isset(self::LETTERS[$lower])) {
                $out .= $ch;
                $i++;

                continue;
            }

            $next = $i + 1 < $len ? mb_substr($text, $i + 1, 1) : '';
            $nextLower = mb_strtolower($next);

            // o' va g' — apostrof ular bilan BIRGA yagona harf hosil qiladi.
            if (($lower === 'o' || $lower === 'g') && $next === "'") {
                $out .= self::keepCase($lower === 'o' ? 'ў' : 'ғ', $isUpper);
                $i += 2;

                continue;
            }

            // Ikki harfli birikma.
            //
            // DIQQAT: birikmadan keyin apostrof kelsa, u birikma EMAS —
            // apostrof ikkinchi harfga tegishli. "YANGIYOʻL" = y + oʻ + l
            // ("йўл"), "ё" emas: `yo` deb o'qilsa "ЯНГИЁЪЛ" chiqadi.
            $pair = $lower.$nextLower;
            $afterPair = $i + 2 < $len ? mb_substr($text, $i + 2, 1) : '';
            $pairBrokenByApostrophe = $afterPair === "'"
                && ($nextLower === 'o' || $nextLower === 'g');

            if (isset(self::DIGRAPHS[$pair]) && ! $pairBrokenByApostrophe) {
                $out .= self::keepCase(self::DIGRAPHS[$pair], $isUpper);
                $i += 2;

                continue;
            }

            // So'z boshidagi `e` — "э" (Эшон), so'z ichida — "е" (Арбек).
            if ($lower === 'e' && self::isWordStart($text, $i)) {
                $out .= self::keepCase('э', $isUpper);
                $i++;

                continue;
            }

            $out .= self::keepCase(self::LETTERS[$lower], $isUpper);
            $i++;
        }

        return $out;
    }

    /** Oxirgi qo'shilgan harf katta registrdami? */
    private static function isPrecededByUpper(string $out): bool
    {
        if ($out === '') {
            return false;
        }

        $prev = mb_substr($out, -1, 1);

        return $prev !== mb_strtolower($prev);
    }

    /** Oldingi belgi harf bo'lmasa — so'z boshi. */
    private static function isWordStart(string $text, int $i): bool
    {
        if ($i === 0) {
            return true;
        }

        $prev = mb_strtolower(mb_substr($text, $i - 1, 1));

        return ! isset(self::LETTERS[$prev]) && $prev !== "'";
    }

    private static function keepCase(string $cyr, bool $isUpper): string
    {
        return $isUpper ? mb_strtoupper($cyr) : $cyr;
    }
}
