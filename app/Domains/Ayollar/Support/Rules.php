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

    /** @return array<int, int> */
    public static function sensitiveQuestions(): array
    {
        return self::all()['sensitive_questions'] ?? [];
    }
}
