<?php

declare(strict_types=1);

namespace Tests\Feature\Ayollar;

use App\Domains\Ayollar\Services\AnketaValidator;
use App\Domains\Ayollar\Services\FormSchemaResolver;
use App\Domains\Ayollar\Services\PiiCipher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saqlashdan oldingi tekshiruv, PII shifrlash va yoshga moslashuvchan
 * sxema — promt §1.5, §1.6 va §6.
 */
class AnketaValidationTest extends AyollarTestCase
{
    private AnketaValidator $validator;

    private FormSchemaResolver $schema;

    private PiiCipher $cipher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = app(AnketaValidator::class);
        $this->schema = app(FormSchemaResolver::class);
        $this->cipher = app(PiiCipher::class);
    }

    /** @return array<string, mixed> */
    private function fullAnswers(): array
    {
        $answers = [];
        for ($q = 1; $q <= 31; $q++) {
            $answers["q{$q}"] = 'javob';
        }
        $answers['q11'] = 'rasmiy_davlat';
        $answers['q12'] = 'yoq';
        $answers['q13'] = 'yoq';

        return $answers;
    }

    // ---------------------------------------------------------------
    // VALIDATSIYA
    // ---------------------------------------------------------------

    public function test_valid_anketa_passes(): void
    {
        $errors = $this->validator->validate(
            $this->fullAnswers(),
            Carbon::now()->subYears(30),
            Carbon::now(),
        );

        $this->assertSame([], $errors, 'To‘liq anketa xatosiz o‘tishi kerak.');
    }

    /** Rozilik imzosisiz anketa SAQLANMAYDI (promt §6.4). */
    public function test_consent_is_mandatory(): void
    {
        $errors = $this->validator->validate(
            $this->fullAnswers(),
            Carbon::now()->subYears(30),
            null,
        );

        $this->assertContains('consent_missing', array_column($errors, 'code'));
    }

    public function test_future_birth_date_is_rejected(): void
    {
        $errors = $this->validator->validate([], Carbon::now()->addDay(), Carbon::now());

        $this->assertContains('birth_future', array_column($errors, 'code'));
    }

    public function test_impossible_age_is_rejected(): void
    {
        $errors = $this->validator->validate([], Carbon::now()->subYears(130), Carbon::now());

        $this->assertContains('birth_too_old', array_column($errors, 'code'));
    }

    /**
     * Sana buzuq bo'lsa qolgan tekshiruvlar yurmaydi.
     *
     * Aks holda «31 ta savol to‘ldirilmagan» xatolari asl sababni —
     * noto'g'ri sanani — ko'mib yuborardi.
     */
    public function test_broken_date_short_circuits_other_checks(): void
    {
        $errors = $this->validator->validate([], Carbon::now()->addYear(), null);

        $this->assertCount(1, $errors);
        $this->assertSame('birth_future', $errors[0]['code']);
    }

    public function test_missing_required_question_is_reported_with_number(): void
    {
        $answers = $this->fullAnswers();
        unset($answers['q7']);

        $errors = $this->validator->validate($answers, Carbon::now()->subYears(30), Carbon::now());

        $missing = array_filter($errors, fn ($e) => $e['code'] === 'required_missing');
        $this->assertNotEmpty($missing);
        $this->assertContains(7, array_column($missing, 'question'));
    }

    /** «Istak» savollari IXTIYORIY — ular balansga ta'sir qilmaydi. */
    public function test_need_questions_are_optional(): void
    {
        $answers = $this->fullAnswers();
        foreach ([16, 17, 18, 19, 21, 26, 28] as $q) {
            unset($answers["q{$q}"]);
        }

        $errors = $this->validator->validate($answers, Carbon::now()->subYears(30), Carbon::now());

        $this->assertSame([], $errors);
    }

    /** Bandlik holati aynan BITTA bo'lishi kerak. */
    public function test_multiple_employment_is_rejected(): void
    {
        $answers = $this->fullAnswers();
        $answers['q11'] = ['rasmiy_davlat', 'norasmiy_band'];

        $errors = $this->validator->validate($answers, Carbon::now()->subYears(30), Carbon::now());

        $this->assertContains('employment_multiple', array_column($errors, 'code'));
    }

    // ---------------------------------------------------------------
    // JShShIR DUBLIKATI — butun viloyat bo'yicha
    // ---------------------------------------------------------------

    public function test_duplicate_pinfl_is_detected_across_districts(): void
    {
        $pinfl = '31234567890123';

        $d1 = $this->someDistrictId();
        $d2 = $this->otherDistrictId($d1);

        $this->makeWoman($this->makeHousehold($this->someMahallaId($d1), $d1), 30, $pinfl);

        // Boshqa TUMANda bir xil JShShIR — dublikat sifatida ushlanishi kerak.
        $errors = $this->validator->validate(
            $this->fullAnswers(),
            Carbon::now()->subYears(30),
            Carbon::now(),
            $pinfl,
        );

        $this->assertContains('pinfl_duplicate', array_column($errors, 'code'));
    }

    /** Dublikat QAYERDA ekani qaytariladi — faol MFY bilan bog'lansin. */
    public function test_duplicate_reports_location(): void
    {
        $pinfl = '31234567890124';
        $district = $this->someDistrictId();
        $mahalla = $this->someMahallaId($district);

        $woman = $this->makeWoman($this->makeHousehold($mahalla, $district), 30, $pinfl);

        $found = $this->validator->findDuplicate($pinfl);

        $this->assertNotNull($found);
        $this->assertSame((string) $woman->id, $found['woman_id']);
        $this->assertSame($mahalla, $found['mahalla_id']);
    }

    /** Tahrirlashda ayolning O'ZI dublikat deb hisoblanmaydi. */
    public function test_own_record_is_not_a_duplicate(): void
    {
        $pinfl = '31234567890125';
        $district = $this->someDistrictId();
        $woman = $this->makeWoman($this->makeHousehold($this->someMahallaId($district), $district), 30, $pinfl);

        $this->assertNull($this->validator->findDuplicate($pinfl, (string) $woman->id));
    }

    // ---------------------------------------------------------------
    // PII SHIFRLASH
    // ---------------------------------------------------------------

    public function test_pinfl_is_encrypted_at_rest(): void
    {
        $pinfl = '31234567890126';
        $district = $this->someDistrictId();
        $woman = $this->makeWoman($this->makeHousehold($this->someMahallaId($district), $district), 30, $pinfl);

        $raw = DB::connection('ayollar')
            ->table('women')->where('id', $woman->id)->value('pinfl_encrypted');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString($pinfl, (string) $raw, 'JShShIR bazada XOM saqlanmoqda.');
        $this->assertStringStartsWith('v1:', (string) $raw);
    }

    /** Accessor MASKALANGAN qiymat qaytaradi — xom qiymat tasodifan sizmasin. */
    public function test_accessor_returns_masked_value(): void
    {
        $pinfl = '31234567890127';
        $district = $this->someDistrictId();
        $woman = $this->makeWoman($this->makeHousehold($this->someMahallaId($district), $district), 30, $pinfl);

        $this->assertSame('••••••••••0127', $woman->pinfl);
        $this->assertStringNotContainsString('3123456789', $woman->pinfl);
    }

    /** Shifrlangan ustunlar JSON javobga UMUMAN tushmaydi. */
    public function test_encrypted_columns_never_serialize(): void
    {
        $district = $this->someDistrictId();
        $woman = $this->makeWoman($this->makeHousehold($this->someMahallaId($district), $district), 30, '31234567890128');

        $json = $woman->toArray();

        foreach (['pinfl_encrypted', 'passport_encrypted', 'phone_encrypted', 'pinfl_hash'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $json, "{$hidden} javobga tushdi.");
        }
    }

    /** Xom qiymat FAQAT ataylab chaqirilganda ochiladi. */
    public function test_raw_pii_requires_explicit_call(): void
    {
        $pinfl = '31234567890129';
        $district = $this->someDistrictId();
        $woman = $this->makeWoman($this->makeHousehold($this->someMahallaId($district), $district), 30, $pinfl);

        $this->assertSame($pinfl, $woman->revealRawPii('pinfl'));
        $this->assertNull($woman->revealRawPii('nomavjud'));
    }

    /** Bir xil qiymat har safar BOSHQA shifrmatn beradi (GCM nonce). */
    public function test_encryption_is_not_deterministic(): void
    {
        $a = $this->cipher->encrypt('31234567890130');
        $b = $this->cipher->encrypt('31234567890130');

        $this->assertNotSame($a, $b);
        $this->assertSame('31234567890130', $this->cipher->decrypt($a));
        $this->assertSame('31234567890130', $this->cipher->decrypt($b));
    }

    /** Hash esa DETERMINISTIK — dublikat topilishi uchun. */
    public function test_hash_is_deterministic_and_ignores_spacing(): void
    {
        $this->assertSame(
            $this->cipher->hash('31234567890131'),
            $this->cipher->hash(' 3123 4567 890131 '),
        );
        $this->assertNotSame(
            $this->cipher->hash('31234567890131'),
            $this->cipher->hash('31234567890132'),
        );
    }

    /** O'zgartirilgan shifrmatn `null` beradi — GCM teg ushlaydi. */
    public function test_tampered_ciphertext_returns_null(): void
    {
        $payload = (string) $this->cipher->encrypt('31234567890133');
        $tampered = substr($payload, 0, -4).'AAAA';

        $this->assertNull($this->cipher->decrypt($tampered));
        $this->assertNull($this->cipher->decrypt('buzuq'));
        $this->assertNull($this->cipher->decrypt(null));
    }

    // ---------------------------------------------------------------
    // YOSHGA MOSLASHUVCHAN SXEMA (§1.5)
    // ---------------------------------------------------------------

    public function test_schema_hides_sections_by_age(): void
    {
        $this->assertSame([1], array_column($this->schema->forAge(1)['sections'], 'number'));
        $this->assertSame([1], array_column($this->schema->forAge(5)['sections'], 'number'));
        $this->assertSame([1, 4], array_column($this->schema->forAge(12)['sections'], 'number'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($this->schema->forAge(25)['sections'], 'number'));
    }

    /** 18+ da 31 savol — 30 emas (promt §1.4 dagi raqamlash xatosi). */
    public function test_adult_form_has_31_questions(): void
    {
        $this->assertSame(31, $this->schema->forAge(25)['total_questions']);
    }

    /** Yashirilgan bo'limlar ham qaytariladi — faol nima ko'rmayotganini bilsin. */
    public function test_hidden_sections_are_listed(): void
    {
        $schema = $this->schema->forAge(5);

        $this->assertCount(4, $schema['hidden_sections']);
    }

    /** Bandlik savoli bolaga KO'RINMAYDI. */
    public function test_employment_question_hidden_for_children(): void
    {
        $this->assertFalse($this->schema->isVisible(1, 11));
        $this->assertFalse($this->schema->isVisible(5, 11));
        $this->assertFalse($this->schema->isVisible(12, 11));
        $this->assertTrue($this->schema->isVisible(18, 11));
    }

    /**
     * Yoshga tegishli bo'lmagan javoblar TASHLANADI.
     *
     * Tug'ilgan sana tuzatilganda eski javoblar qolib ketsa, 2 yoshli qiz
     * «ishsiz» toifasiga o'tib ketardi.
     */
    public function test_prune_removes_out_of_age_answers(): void
    {
        $pruned = $this->schema->pruneAnswers(['q1' => 'a', 'q11' => 'ishsiz', 'q30' => 'x'], 2);

        $this->assertArrayHasKey('q1', $pruned);
        $this->assertArrayNotHasKey('q11', $pruned);
        $this->assertArrayNotHasKey('q30', $pruned);
    }

    /** Anketa saqlanganda ham prune ishlaydi — bola yosh qatorida qoladi. */
    public function test_child_with_stray_employment_answer_stays_in_age_row(): void
    {
        $district = $this->someDistrictId();
        $hh = $this->makeHousehold($this->someMahallaId($district), $district);
        $woman = $this->makeWoman($hh, 2);

        $anketa = $this->makeAnketa($woman, ['q1' => 'ism', 'q11' => 'ishsiz', 'q12' => 'yoq']);

        $this->assertSame('age_0_2', $anketa->balance_row);
        $this->assertArrayNotHasKey('q11', $anketa->answers);
    }
}
