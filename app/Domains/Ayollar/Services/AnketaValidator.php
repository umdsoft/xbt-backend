<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use App\Domains\Ayollar\Models\Woman;
use App\Domains\Ayollar\Support\Rules;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Saqlashdan OLDINGI majburiy tekshiruv (promt §1.6).
 *
 * Beshta tekshiruv `Anketa 6 · Yakuniy tekshiruv` ekranida ham
 * ko'rsatiladi — shuning uchun natija massiv, istisno EMAS: ekran
 * qaysi tekshiruv o'tmaganini bittalab ko'rsatishi kerak, birinchisida
 * to'xtab qolmasligi kerak.
 */
class AnketaValidator
{
    public function __construct(
        private readonly CategoryResolver $resolver,
        private readonly FormSchemaResolver $schema,
        private readonly PiiCipher $cipher,
    ) {}

    /**
     * Anketani tekshiradi.
     *
     * @param  array<string, mixed>  $answers
     * @param  string|null  $pinfl  xom JShShIR (dublikat tekshiruvi uchun)
     * @param  string|null  $exceptWomanId  tahrirlashda o'zini hisobga olmaslik
     * @return array<int, array{code: string, message: string, question: int|null}>
     *         bo'sh massiv = tekshiruvdan o'tdi
     */
    public function validate(
        array $answers,
        CarbonInterface $birthDate,
        ?Carbon $consentSignedAt,
        ?string $pinfl = null,
        ?string $exceptWomanId = null,
    ): array {
        $errors = [];

        // 1. Tug'ilgan sana ishonchli.
        if ($birthDate->isFuture()) {
            $errors[] = $this->err('birth_future', 'Tug‘ilgan sana kelajakda bo‘lishi mumkin emas.', 2);
        }

        $age = $this->resolver->ageAt($birthDate);

        if ($age > 120) {
            $errors[] = $this->err('birth_too_old', 'Tug‘ilgan sana 120 yoshdan oshiq — xato kiritilgan.', 2);
        }

        // Sana buzuq bo'lsa qolgan tekshiruvlar ma'nosiz: yosh guruhi
        // noto'g'ri chiqadi va «majburiy savol to‘ldirilmagan» xatolari
        // asl sababni ko'mib yuborardi.
        if ($errors !== []) {
            return $errors;
        }

        // 2. Yosh guruhiga tegishli majburiy savollar to'ldirilgan.
        foreach ($this->requiredFor($age) as $question) {
            if (! $this->answered($answers, "q{$question}")) {
                $errors[] = $this->err(
                    'required_missing',
                    "{$question}-savol to‘ldirilmagan.",
                    $question,
                );
            }
        }

        // 3. Bandlik holati AYNAN BITTA tanlangan.
        $employmentKey = Rules::fieldToQuestionKey('employment_status') ?? 'q11';

        if ($this->schema->isVisible($age, (int) substr($employmentKey, 1))) {
            $value = $answers[$employmentKey] ?? null;

            if (is_array($value)) {
                $errors[] = $this->err(
                    'employment_multiple',
                    'Bandlik holati aynan bitta bo‘lishi kerak.',
                    (int) substr($employmentKey, 1),
                );
            }
        }

        // 4. Rozilik imzosi mavjud.
        //
        // Imzosiz anketa SAQLANMAYDI (promt §6.4). Bu texnik emas, huquqiy
        // shart: rozilksiz yig'ilgan maxsus toifadagi ma'lumot noqonuniy.
        if ($consentSignedAt === null) {
            $errors[] = $this->err('consent_missing', 'Rozilik imzosi qo‘yilmagan.', null);
        }

        // 5. JShShIR dublikati — BUTUN VILOYAT bo'yicha.
        if ($pinfl !== null && $pinfl !== '') {
            $duplicate = $this->findDuplicate($pinfl, $exceptWomanId);

            if ($duplicate !== null) {
                $errors[] = $this->err(
                    'pinfl_duplicate',
                    'Bu JShShIR allaqachon ro‘yxatdan o‘tgan.',
                    null,
                );
            }
        }

        return $errors;
    }

    /**
     * Dublikatni topadi va QAYERDA ekanini qaytaradi.
     *
     * Faqat «dublikat bor» demaslik muhim: faol qaysi MFY'da ekanini
     * ko'rsa, o'sha MFY bilan bog'lanib aniqlaydi. «Bu ayol 12-MFYda
     * ro'yxatdan o'tgan» (promt §8.4).
     *
     * @return array{woman_id: string, mahalla_id: string, district_id: string}|null
     */
    public function findDuplicate(string $pinfl, ?string $exceptWomanId = null): ?array
    {
        $query = Woman::query()
            ->where('pinfl_hash', $this->cipher->hash($pinfl));

        if ($exceptWomanId !== null) {
            $query->whereKeyNot($exceptWomanId);
        }

        $found = $query->first(['id', 'mahalla_id', 'district_id']);

        return $found === null ? null : [
            'woman_id' => (string) $found->id,
            'mahalla_id' => (string) $found->mahalla_id,
            'district_id' => (string) $found->district_id,
        ];
    }

    /**
     * Shu yosh guruhi uchun majburiy savollar.
     *
     * Hozircha yosh guruhining BARCHA savollari majburiy. Haqiqiy anketa
     * kelganda ixtiyoriy savollar `rules.json` da belgilanadi — shuning
     * uchun ro'yxat shu yerda ALOHIDA metodda, sxemaga aralashtirilmagan.
     *
     * @return array<int, int>
     */
    private function requiredFor(int $age): array
    {
        $questions = $this->schema->forAge($age)['questions'];

        // «Istak» savollari ixtiyoriy: ular balansga ta'sir qilmaydi,
        // faqat ehtiyojlar xaritasini boyitadi. Majburiy qilinsa, faol
        // javob bilmagan joyda tasodifiy variant tanlashga majbur
        // bo'lardi va xarita yolg'on ma'lumot bilan to'lardi.
        $optional = Rules::needQuestions();

        return array_values(array_diff($questions, $optional));
    }

    /** @param array<string, mixed> $answers */
    private function answered(array $answers, string $key): bool
    {
        $value = $answers[$key] ?? null;

        if ($value === null || $value === '') {
            return false;
        }

        return ! (is_array($value) && $value === []);
    }

    /** @return array{code: string, message: string, question: int|null} */
    private function err(string $code, string $message, ?int $question): array
    {
        return ['code' => $code, 'message' => $message, 'question' => $question];
    }
}
