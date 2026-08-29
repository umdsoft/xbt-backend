<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use App\Domains\Ayollar\Support\Rules;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * TOIFALASH — tizimning yagona hisoblash nuqtasi.
 *
 * Hech kim hech qachon toifani qo'lda kiritmaydi. Faol anketani to'ldiradi,
 * shu servis toifani aniqlaydi, balans esa toifalar yig'indisidan chiqadi.
 *
 * BUZILMAS QOIDA (promt §1.1):
 *     YASHIL ∪ SARIQ = JAMI    (kesishmaydi, to'liq qoplaydi)
 *     YASHIL ∩ SARIQ = ∅
 *     QIZIL ⊆ (YASHIL ∪ SARIQ)  (ustma-ust, alohida bo'lak EMAS)
 *
 * Birinchi ikki qoida ZINAPOYA tuzilishidan kelib chiqadi: 22 qadam yuqoridan
 * pastga tekshiriladi va BIRINCHI mos kelganda to'xtaydi. Ya'ni har ayol
 * qat'iy bitta qatorga tushadi — bu `if/elseif` zanjiri emas, `foreach +
 * return`, shuning uchun yangi qadam qo'shilganda ham qoida buzilmaydi.
 *
 * Uchinchi qoida esa qizil belgilarni ALOHIDA hisoblash bilan ta'minlanadi:
 * ular toifani o'zgartirmaydi, faqat ustiga qo'shiladi.
 *
 * Qadamlar `resources/ayollar/rules.json` da — frontend ham SHU faylni
 * o'qiydi (promt §14: mantiq frontendda takrorlanmaydi).
 */
class CategoryResolver
{
    /**
     * Anketani toifalaydi.
     *
     * @param  array<string, mixed>  $answers  anketa javoblari (`q1`..`q31`)
     * @param  int  $age  to'ldirilgan paytdagi to'liq yosh
     */
    public function resolve(array $answers, int $age): CategoryResolution
    {
        if ($age < 0 || $age > 120) {
            throw new InvalidArgumentException("Yosh oralig'i noto'g'ri: {$age}");
        }

        $trace = [];

        foreach (Rules::ladder() as $rung) {
            $condition = $rung['when'];
            $actual = $this->valueOf($condition['field'], $answers, $age);
            $matched = $this->matches($condition, $actual);

            $trace[] = [
                'step' => $rung['step'],
                'field' => $condition['field'],
                'op' => $condition['op'],
                'expected' => $condition['value'] ?? null,
                'actual' => $actual,
                'matched' => $matched,
                'balance_row' => $rung['balance_row'],
            ];

            if ($matched) {
                return new CategoryResolution(
                    category: $rung['category'],
                    balanceRow: $rung['balance_row'],
                    step: $rung['step'],
                    trace: $trace,
                    redFlags: $this->redFlagsFor($answers),
                    rulesVersion: Rules::version(),
                );
            }
        }

        // 23-qadam: hech biri mos kelmadi. Bu XATO EMAS — anketa hali
        // to'liq emas. U balansdan tashqarida qoladi va `yashil + sariq =
        // jami` tengligini BUZMAYDI, chunki jamiga ham kirmaydi.
        return new CategoryResolution(
            category: CategoryResolution::INCOMPLETE,
            balanceRow: null,
            step: (int) (Rules::all()['incomplete']['step'] ?? 23),
            trace: $trace,
            redFlags: $this->redFlagsFor($answers),
            rulesVersion: Rules::version(),
        );
    }

    /** Tug'ilgan sanadan to'liq yoshni hisoblab, toifalaydi. */
    public function resolveByBirthDate(array $answers, CarbonInterface $birthDate, ?CarbonInterface $at = null): CategoryResolution
    {
        return $this->resolve($answers, $this->ageAt($birthDate, $at));
    }

    /**
     * To'liq yosh.
     *
     * `diffInYears` ATAYLAB: oy/kun hisobga olinadi. Yilni ayirish (2026−2008)
     * tug'ilgan kuni hali kelmagan qizni 18 yoshli deb ko'rsatardi va u V
     * bo'lim savollarini (ijtimoiy nazorat) noto'g'ri olardi.
     */
    public function ageAt(CarbonInterface $birthDate, ?CarbonInterface $at = null): int
    {
        return (int) $birthDate->diffInYears($at ?? now());
    }

    /**
     * Yosh guruhi kodi (`0_2`, `3_6`, `7_17`, `18_up`).
     *
     * Guruh anketa bilan birga SAQLANADI: qiz keyingi yili katta guruhga
     * o'tsa ham, o'tgan davr balansi o'zgarmasligi kerak.
     */
    public function ageGroup(int $age): string
    {
        foreach (Rules::ageGroups() as $group) {
            if ($age >= $group['min'] && $age <= $group['max']) {
                return $group['code'];
            }
        }

        throw new InvalidArgumentException("Yosh guruhi topilmadi: {$age}");
    }

    /**
     * Qizil belgilar — TOIFADAN MUSTAQIL.
     *
     * Cheklanmagan: bitta ayolda 5 tagacha bo'lishi mumkin. Ular toifani
     * o'zgartirmaydi — norasmiy band ayol zo'ravonlik qurboni bo'lsa ham
     * `yel_informal` qatorida qoladi, ustiga qizil belgi qo'shiladi.
     *
     * @param  array<string, mixed>  $answers
     * @return array<int, array{code: string, source_question: int}>
     */
    public function redFlagsFor(array $answers): array
    {
        $flags = [];

        foreach (Rules::redFlags() as $flag) {
            $actual = $this->valueOf($flag['when']['field'], $answers, null);

            if ($this->matches($flag['when'], $actual)) {
                $flags[] = [
                    'code' => $flag['code'],
                    'source_question' => $flag['source_question'],
                ];
            }
        }

        return $flags;
    }

    /**
     * Shart bajarildimi.
     *
     * Operatorlar ataylab OZ (`eq`, `neq`, `between`, `in`, `truthy`):
     * qoida fayli ifoda tiliga aylanib ketmasligi kerak. Murakkab shart
     * kerak bo'lsa, u yangi semantik maydon bo'lib chiqadi — anketada
     * hisoblanadi, qoidada emas.
     *
     * @param  array<string, mixed>  $condition
     */
    private function matches(array $condition, mixed $actual): bool
    {
        return match ($condition['op']) {
            'eq' => $actual === $condition['value'],
            'neq' => $actual !== null && $actual !== $condition['value'],
            'in' => is_scalar($actual) && in_array($actual, $condition['value'], true),
            'between' => is_int($actual)
                && $actual >= $condition['value'][0]
                && $actual <= $condition['value'][1],
            'truthy' => $this->isTruthy($actual),
            default => throw new InvalidArgumentException("Noma'lum operator: {$condition['op']}"),
        };
    }

    /**
     * «Ha» deb hisoblanadigan qiymatlar.
     *
     * Anketa javobi planshetdan `true`, veb formadan `"ha"`, eski importdan
     * `1` bo'lib kelishi mumkin. Uchalasi ham bir xil ma'noda — aks holda
     * qizil belgi manbaga qarab jimgina yo'qolardi.
     */
    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(mb_strtolower(trim($value)), ['ha', 'yes', 'true', '1', 'ҳа'], true);
        }

        return false;
    }

    /**
     * Maydon qiymatini oladi.
     *
     * Uch xil manba:
     *   `age`               — javoblardan emas, hisoblangan yoshdan
     *   `employment_status` — semantik kalit, `fields` xaritasi orqali `q11` ga
     *   `q31.violence`      — ichma-ich javob (ko'p tanlovli savol)
     *
     * @param  array<string, mixed>  $answers
     */
    private function valueOf(string $field, array $answers, ?int $age): mixed
    {
        if ($field === 'age') {
            return $age;
        }

        // Ichma-ich yo'l: `q31.violence`.
        if (str_contains($field, '.')) {
            return data_get($answers, $field);
        }

        // Semantik kalit -> savol kaliti.
        $questionKey = Rules::fieldToQuestionKey($field);

        if ($questionKey !== null) {
            return $answers[$questionKey] ?? null;
        }

        // To'g'ridan-to'g'ri savol kaliti (`q27`).
        return $answers[$field] ?? null;
    }
}
