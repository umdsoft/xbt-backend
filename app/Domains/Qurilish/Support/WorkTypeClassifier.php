<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Support;

/**
 * Ish turini aniqlaydi: `ПҚ-393` da guruh-sarlavhada («Янги қуриш»,
 * «Реконструкция», «Мукаммал таъмирлаш»), qolgan varaqlarda faqat obyekt
 * nomidagi so'zdan.
 */
class WorkTypeClassifier
{
    /** @var array<string, string> normallashtirilgan yorliq -> kod */
    private const LABELS = [
        'yangiqurish' => 'yangi_qurish',
        'rekonstruktsiya' => 'rekonstruksiya',
        'rekonstruksiya' => 'rekonstruksiya',
        'mukammaltamirlash' => 'mukammal_tamirlash',
        'kapitaltamirlash' => 'kapital_tamirlash',
        'joriytamirlash' => 'joriy_tamirlash',
    ];

    /**
     * Nomdagi kalit so'z -> kod. TARTIB MUHIM: «мукаммал таъмирлаш»
     * «таъмирлаш» dan oldin.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const KEYWORDS = [
        ['реконструкция', 'rekonstruksiya'],
        ['мукаммал таъмир', 'mukammal_tamirlash'],
        ['капитал таъмир', 'kapital_tamirlash'],
        ['жорий таъмир', 'joriy_tamirlash'],
        ['таъмирлаш', 'mukammal_tamirlash'],
        ['қуриш', 'yangi_qurish'],
        ['куриш', 'yangi_qurish'],
        ['барпо эт', 'yangi_qurish'],
    ];

    public function classify(?string $label, string $name): ?string
    {
        $key = $this->normalize($label);
        if ($key !== '' && isset(self::LABELS[$key])) {
            return self::LABELS[$key];
        }

        $n = mb_strtolower($name);
        foreach (self::KEYWORDS as [$needle, $code]) {
            if (str_contains($n, $needle)) {
                return $code;
            }
        }

        return null;
    }

    private function normalize(?string $label): string
    {
        if ($label === null) {
            return '';
        }

        $s = mb_strtolower((string) Translit::toLatin(trim($label)));

        return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
    }
}
