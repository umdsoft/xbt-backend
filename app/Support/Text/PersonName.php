<?php

declare(strict_types=1);

namespace App\Support\Text;

/**
 * F.I.Sh. NI KO'RSATISH UCHUN STANDARTGA KELTIRISH.
 *
 * SAQLANGAN QIYMATGA TEGILMAYDI. Faol ismni qanday yozgan bo'lsa,
 * bazada shundayligicha qoladi — kirillda, lotinda, katta harfda yoki
 * aralash. Bu ataylab: yozuv hujjatdagi asl shakl bo'lib qolishi
 * kerak va uni qayta yozish ma'lumotni yo'qotish demakdir.
 *
 * Standart FAQAT ro'yxatlarda qo'llanadi, chunki muammo aynan shu
 * yerda ko'rinadi: bitta jadvalda «АБДУЛЛАЕВА САНОБАР», «Salayeva
 * Halima» va «bobojonova soxiba» yonma-yon turadi va ro'yxatni
 * o'qib ham, saralab ham bo'lmaydi.
 *
 * STANDART: lotin yozuvi, har so'z bosh harf bilan.
 *
 * NEGA LOTIN: interfeys lotinda va hozirgi ma'lumotning 70% i ham
 * lotinda (6554 ta ayolning 4578 tasi). Kirillga o'girish ko'proq
 * yozuvni o'zgartirardi.
 */
final class PersonName
{
    /**
     * Kirill -> lotin. O'zbek alifbosi.
     *
     * TARTIB MUHIM: ko'p harfli birikmalar (ch, sh, yo) BIRINCHI
     * turishi kerak, aks holda `ч` ni `c`+`h` deb emas, bitta harf
     * sifatida almashtirish imkoni yo'qoladi.
     */
    private const CYR_TO_LAT = [
        'ё' => 'yo', 'Ё' => 'Yo', 'ж' => 'j', 'Ж' => 'J',
        'ч' => 'ch', 'Ч' => 'Ch', 'ш' => 'sh', 'Ш' => 'Sh',
        'щ' => 'sh', 'Щ' => 'Sh', 'ю' => 'yu', 'Ю' => 'Yu',
        'я' => 'ya', 'Я' => 'Ya', 'ц' => 'ts', 'Ц' => 'Ts',
        'ў' => 'oʻ', 'Ў' => 'Oʻ', 'ғ' => 'gʻ', 'Ғ' => 'Gʻ',
        'қ' => 'q', 'Қ' => 'Q', 'ҳ' => 'h', 'Ҳ' => 'H',
        'х' => 'x', 'Х' => 'X', 'ъ' => 'ʼ', 'Ъ' => 'ʼ', 'ь' => '', 'Ь' => '',
        'а' => 'a', 'А' => 'A', 'б' => 'b', 'Б' => 'B', 'в' => 'v', 'В' => 'V',
        'г' => 'g', 'Г' => 'G', 'д' => 'd', 'Д' => 'D', 'е' => 'e', 'Е' => 'E',
        'з' => 'z', 'З' => 'Z', 'и' => 'i', 'И' => 'I', 'й' => 'y', 'Й' => 'Y',
        'к' => 'k', 'К' => 'K', 'л' => 'l', 'Л' => 'L', 'м' => 'm', 'М' => 'M',
        'н' => 'n', 'Н' => 'N', 'о' => 'o', 'О' => 'O', 'п' => 'p', 'П' => 'P',
        'р' => 'r', 'Р' => 'R', 'с' => 's', 'С' => 'S', 'т' => 't', 'Т' => 'T',
        'у' => 'u', 'У' => 'U', 'ф' => 'f', 'Ф' => 'F', 'ы' => 'i', 'Ы' => 'I',
        'э' => 'e', 'Э' => 'E',
    ];

    /**
     * O'zbek ismlarida KICHIK harf bilan qoladigan qo'shimchalar.
     *
     * «Bobojonova Sabrina Mansurbek Qizi» noto'g'ri — `qizi` va
     * `o'g'li` ism emas, ular ota ismiga qo'shiladigan so'z.
     */
    private const LOWER_SUFFIX = ['qizi', 'oʻgʻli', 'ogli', 'oglu', 'kizi'];

    /** Ro'yxatda ko'rsatiladigan shakl. Bo'sh bo'lsa — bo'sh satr. */
    public static function standard(?string $name): string
    {
        $value = trim((string) $name);

        if ($value === '') {
            return '';
        }

        $latin = strtr(self::resolveYe($value), self::CYR_TO_LAT);

        // Ko'p bo'shliq, tab va yangi qator — bitta bo'shliqqa.
        $latin = (string) preg_replace('/\s+/u', ' ', $latin);

        $words = array_map(self::word(...), explode(' ', $latin));

        return implode(' ', $words);
    }

    /**
     * `е` — `ye` yoki `e`.
     *
     * O'zbek qoidasi: so'z boshida, unlidan va ъ/ь dan keyin `ye`,
     * qolgan joyda `e`. Buni hisobga olmasa, familiyalarning katta
     * qismi noto'g'ri chiqadi — «Абдуллаева» `Abdullaeva` bo'lardi,
     * to'g'risi esa `Abdullayeva`. Bu ism, ya'ni imlo xatosi
     * hujjatdagi odamni boshqa odamga aylantiradi.
     *
     * Almashtirish TABLITSADAN OLDIN bajariladi: jadval `е` ni
     * kontekstsiz bitta harfga o'girib yuborardi.
     */
    private static function resolveYe(string $value): string
    {
        $out = '';
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $vowels = ['а', 'е', 'ё', 'и', 'о', 'у', 'ў', 'э', 'ю', 'я', 'ъ', 'ь'];

        foreach ($chars as $i => $ch) {
            if ($ch !== 'е' && $ch !== 'Е') {
                $out .= $ch;

                continue;
            }

            $prev = $i > 0 ? mb_strtolower($chars[$i - 1]) : '';
            $soft = $prev === '' || ! preg_match('/\p{L}/u', $prev) || in_array($prev, $vowels, true);

            if (! $soft) {
                $out .= $ch === 'е' ? 'э' : 'Э';   // jadvalda `э` -> `e`

                continue;
            }

            $out .= $ch === 'е' ? 'йэ' : 'Йэ';     // jadvalda `й`+`э` -> `ye`
        }

        return $out;
    }

    /**
     * Bitta so'z: bosh harf katta, qolgani kichik.
     *
     * Tire va apostrof bilan bog'langan qismlar ALOHIDA so'z
     * hisoblanadi: «Abdulla-Qizi» emas, «Abdulla-Qizi» — har ikkala
     * qismi ham o'z bosh harfini oladi.
     */
    private static function word(string $word): string
    {
        if ($word === '') {
            return '';
        }

        if (in_array(mb_strtolower($word), self::LOWER_SUFFIX, true)) {
            return mb_strtolower($word);
        }

        // Tire bilan ajralgan qismlar — har biri bosh harfli.
        if (str_contains($word, '-')) {
            return implode('-', array_map(self::capitalize(...), explode('-', $word)));
        }

        return self::capitalize($word);
    }

    private static function capitalize(string $part): string
    {
        if ($part === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($part, 0, 1)).mb_strtolower(mb_substr($part, 1));
    }
}
