<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use App\Domains\Ayollar\Support\Rules;
use InvalidArgumentException;

/**
 * Yoshga moslashuvchan anketa sxemasi.
 *
 * FRONTEND HECH QACHON O'ZI HAL QILMAYDI (promt §1.5). Planshet «qaysi
 * savollarni ko'rsatay?» deb serverdan so'raydi — yoki oflayn rejimda
 * keshlangan `rules.json` dan o'qiydi. Bir xil fayl, bir xil natija.
 *
 * NEGA MUHIM: 2 yoshli qizga bandlik savolini ko'rsatish shunchaki noqulay
 * emas — javob berilsa, u toifalash zinapoyasiga tushib, balansni buzardi.
 * Sxema — ma'lumot sifatining birinchi darvozasi.
 */
class FormSchemaResolver
{
    /**
     * Yosh guruhi uchun to'liq sxema.
     *
     * @return array{
     *     age_group: string,
     *     sections: array<int, array<string, mixed>>,
     *     questions: array<int, int>,
     *     hidden_sections: array<int, array<string, mixed>>,
     *     total_questions: int
     * }
     */
    public function forAge(int $age): array
    {
        $group = $this->groupFor($age);
        $visibleSections = $group['sections'];
        $all = Rules::sections();

        return [
            'age_group' => $group['code'],
            'sections' => array_values(array_filter(
                $all,
                fn (array $s) => in_array($s['number'], $visibleSections, true),
            )),
            'questions' => $group['questions'],
            // Yashirilgan bo'limlar RO'YXATI ham qaytariladi: planshetning
            // chap railida «Yashirilgan» bo'limi ko'rinadi (promt §10.10).
            // Faol nima ko'rmayotganini BILISHI kerak — aks holda «anketa
            // to'liq emas» degan shubha qoladi.
            'hidden_sections' => array_values(array_filter(
                $all,
                fn (array $s) => ! in_array($s['number'], $visibleSections, true),
            )),
            'total_questions' => count($group['questions']),
        ];
    }

    /** Savol shu yosh uchun ko'rinadimi. */
    public function isVisible(int $age, int $question): bool
    {
        return in_array($question, $this->groupFor($age)['questions'], true);
    }

    /**
     * Javoblardan yoshga tegishli BO'LMAGAN kalitlarni tashlaydi.
     *
     * Planshet noto'g'ri javob yuborsa (masalan tug'ilgan sana tuzatilib,
     * yosh guruhi o'zgargandan keyin eski javoblar qolib ketsa), ular
     * jimgina tozalanadi. Xato qaytarish faolni tushunarsiz holatda
     * qoldirardi: u ko'rmagan savol uchun xato oladi.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function pruneAnswers(array $answers, int $age): array
    {
        $visible = $this->groupFor($age)['questions'];
        $out = [];

        foreach ($answers as $key => $value) {
            if (! preg_match('/^q(\d+)$/', (string) $key, $m)) {
                continue;
            }

            if (in_array((int) $m[1], $visible, true)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function groupFor(int $age): array
    {
        foreach (Rules::ageGroups() as $group) {
            if ($age >= $group['min'] && $age <= $group['max']) {
                return $group;
            }
        }

        throw new InvalidArgumentException("Yosh guruhi topilmadi: {$age}");
    }
}
