<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Support;

use RuntimeException;

/**
 * `rules.json` — toifalash, qizil belgilar va anketa sxemasining YAGONA manbai.
 *
 * NEGA JSON, PHP MASSIVI EMAS: xuddi shu faylni frontend ham o'qiydi (jonli
 * toifa chizig'i uchun). Qoida PHP'da yozilsa, frontend uni TAKRORLASHi kerak
 * bo'lardi va ikki nusxa vaqt o'tib bir-biridan uzoqlashardi — planshetda
 * «yashil», serverda «sariq». Bitta fayl bunday bo'linishni imkonsiz qiladi.
 *
 * Fayl process xotirasida keshlanadi: har so'rovda diskdan o'qish va JSON
 * parse qilish 1 mln yozuvli qayta hisoblashda seziladigan yuk bo'lardi.
 */
final class Rules
{
    private const PATH = 'ayollar/rules.json';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = resource_path(self::PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Toifalash qoidalari topilmadi: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Toifalash qoidalari buzuq JSON: '.json_last_error_msg());
        }

        return self::$cache = $decoded;
    }

    /** Testlar orasida keshni tozalash uchun. */
    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function version(): string
    {
        return (string) (self::all()['version'] ?? '0');
    }

    /** @return array<int, array<string, mixed>> */
    public static function ladder(): array
    {
        return self::all()['category_ladder'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public static function redFlags(): array
    {
        return self::all()['red_flags'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public static function ageGroups(): array
    {
        return self::all()['age_groups'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public static function sections(): array
    {
        return self::all()['sections'] ?? [];
    }

    /**
     * Semantik kalit -> anketa savoli kaliti (`employment_status` -> `q11`).
     *
     * Bu qatlam BOR, chunki haqiqiy anketa raqamlashi hali tasdiqlanmagan.
     * Raqam o'zgarsa, `rules.json` dagi `fields` xaritasi tahrirlanadi —
     * zinapoyaning 22 qadamiga TEGILMAYDI.
     */
    public static function fieldToQuestionKey(string $field): ?string
    {
        $map = self::all()['fields'] ?? [];

        if (! isset($map[$field]) || ! is_array($map[$field])) {
            return null;
        }

        return 'q'.$map[$field]['question'];
    }

    /** @return array<int, int> */
    public static function needQuestions(): array
    {
        return self::all()['need_questions'] ?? [];
    }

    /**
     * SHARTLI IXTIYORIY BANDLAR — javoblarga qarab.
     *
     * `need_questions` band HAR DOIM ixtiyoriy deydi; bu esa javobga
     * bog'liq. Masalan 10-band («Bandlik holati») ta'limda bo'lgan
     * ayoldan so'ralmaydi.
     *
     * Eskiroq `rules.json` da bu bo'lim bo'lmasligi mumkin —
     * o'shanda bo'sh ro'yxat qaytadi va xatti-harakat o'zgarmaydi.
     *
     * @return array<int, array{question: int, when: array<string, mixed>}>
     */
    public static function optionalWhen(): array
    {
        return self::all()['optional_when'] ?? [];
    }

    /** @return array<int, int> */
    public static function sensitiveQuestions(): array
    {
        return self::all()['sensitive_questions'] ?? [];
    }

    /**
     * BAND MATNI — EKRAN VA PDF UCHUN BITTA MANBA.
     *
     * Avval matnlar faqat frontendda yashardi va backend ularni
     * bilmasdi. Natijada PDFda «1-savol», «2-savol» chiqardi va
     * hujjatni o'qigan odam qaysi savolga javob berilganini qog'oz
     * anketa bilan solishtirmasdan bila olmasdi.
     */
    public static function questionTitle(int $question): string
    {
        $title = self::all()['questions'][(string) $question]['title'] ?? null;

        return is_string($title) && $title !== '' ? $title : $question.'-band';
    }

    /**
     * Bir band ichidagi ichki savollar yorliqlari (2, 8, 17-bandlar).
     *
     * @return array<string, string>
     */
    public static function questionGroups(int $question): array
    {
        $groups = self::all()['questions'][(string) $question]['groups'] ?? [];

        return is_array($groups) ? $groups : [];
    }

    /**
     * Ko'p tanlovli bandning belgilari (26-band).
     *
     * @return array<string, string>
     */
    public static function questionOptions(int $question): array
    {
        $options = self::all()['questions'][(string) $question]['options'] ?? [];

        return is_array($options) ? $options : [];
    }

    /**
     * Qiymatning o'qiladigan nomi: `oila_qurmagan` -> «Oila qurmagan».
     *
     * `enums` da faqat KODLAR turadi, nomlar esa frontendda edi —
     * shuning uchun PDFda xom kod chiqardi. Endi yorliqlar
     * `rules.json` da va ikkala tomon o'shani o'qiydi.
     *
     * Topilmasa QIYMATNING O'ZI qaytadi: nomsiz qolgan yangi kodni
     * yashirish uni «—» ga aylantirardi va javob YO'Q bo'lib
     * ko'rinardi. Xom kod xunuk, lekin rost.
     */
    /**
     * Qator boshqasining ICHIDAN chiqadimi — qog'ozdagi «шундан».
     *
     * Egasining kodini qaytaradi, mustaqil qator uchun `null`.
     * «Shundan» qatori yig'indiga QO'SHILMAYDI: aks holda bir odam
     * ikki marta sanalardi — masalan MTTga qatnaydigan bola «3–6 ёш»
     * qatorida ham, «шундан, мактабгача таълим» qatorida ham.
     */
    public static function subRowOwner(string $code): ?string
    {
        $owner = self::all()['sub_rows'][$code] ?? null;

        return is_string($owner) ? $owner : null;
    }

    public static function label(string $value): string
    {
        $label = self::all()['labels'][$value] ?? null;

        return is_string($label) && $label !== '' ? $label : $value;
    }
}
