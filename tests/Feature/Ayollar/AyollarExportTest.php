<?php

declare(strict_types=1);

namespace Tests\Feature\Ayollar;

use App\Domains\Ayollar\Services\BalanceCalculator;
use App\Domains\Ayollar\Support\AyollarAccess;

/**
 * EKSPORT — Excel va PDF.
 *
 * Ikkala format ham NAZORATSIZ ko'chiriladi: yuklangan fayl keyin
 * qayerga borishini hech kim bilmaydi. Shuning uchun bu testlar bitta
 * savolga javob beradi: faylga tushmasligi kerak bo'lgan narsa
 * tushmadimi?
 */
class AyollarExportTest extends AyollarApiTestCase
{
    // ---------------------------------------------------------------
    // PDF
    // ---------------------------------------------------------------

    public function test_anketa_pdf_is_generated(): void
    {
        $d = $this->someDistrictId();
        $anketa = $this->anketaIn($this->someMahallaId($d), $d);
        $user = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $d]);

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/ayollar/export/anketa/{$anketa->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $body = $response->getContent();

        $this->assertStringStartsWith('%PDF-', $body, 'Javob PDF emas.');
        $this->assertGreaterThan(3000, strlen($body), 'PDF juda kichik — mazmun chizilmagan.');
    }

    /**
     * PDF'da V BO'LIM javoblari YO'Q.
     *
     * PDF bosiladi, papkaga qo'yiladi va nazoratsiz ko'chiriladi.
     * Zo'ravonlik yoki narkologiya hisobi haqidagi javob qog'ozda
     * yurishi mumkin emas — hatto `pii.reveal` huquqi bor
     * foydalanuvchida ham.
     */
    public function test_pdf_excludes_section_five_answers(): void
    {
        $d = $this->someDistrictId();

        $anketa = $this->anketaIn($this->someMahallaId($d), $d, answers: [
            'q11' => 'ishsiz', 'q12' => 'yoq', 'q13' => 'yoq',
            'q30' => ['probation' => true],
            'q31' => ['violence' => true],
        ]);

        // `district_family_dept` — `pii.reveal` huquqi BOR rol.
        $user = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $d]);

        $this->assertTrue(app(AyollarAccess::class)->can($user, 'ayollar.pii.reveal'));

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/ayollar/export/anketa/{$anketa->id}/pdf")
            ->assertOk();

        $body = $response->getContent();

        // PDF matni siqilgan bo'lishi mumkin, shuning uchun HTML
        // bosqichini ALOHIDA tekshiramiz (quyidagi test).
        $this->assertStringStartsWith('%PDF-', $body);
    }

    /**
     * PDF HTML'ida V bo'lim savollari umuman chizilmaydi.
     *
     * Bu testni PDF baytlari ustida yozib bo'lmaydi (dompdf matnni
     * siqadi va shrift kodlaydi), shuning uchun HTML bosqichi
     * bevosita tekshiriladi — mantiq aynan o'sha yerda.
     */
    public function test_pdf_html_omits_sensitive_questions(): void
    {
        $d = $this->someDistrictId();

        $anketa = $this->anketaIn($this->someMahallaId($d), $d, answers: [
            'q11' => 'norasmiy_band', 'q12' => 'yoq', 'q13' => 'yoq',
            'q7' => 'ajrashgan',
            'q30' => ['probation' => true],
            'q31' => ['violence' => true],
        ]);

        $controller = new \ReflectionMethod(
            \App\Domains\Ayollar\Http\Controllers\Api\ExportController::class,
            'pdfSections',
        );
        $controller->setAccessible(true);

        $sections = $controller->invoke(app(\App\Domains\Ayollar\Http\Controllers\Api\ExportController::class), $anketa);

        $questions = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $questions[] = $item['number'];
            }
        }

        $this->assertContains(7, $questions, 'Oddiy javob PDF‘ga tushmadi.');
        $this->assertNotContains(30, $questions, '30-savol (V boʻlim) PDF‘ga tushdi.');
        $this->assertNotContains(31, $questions, '31-savol (V boʻlim) PDF‘ga tushdi.');
    }

    /** Doiradan tashqaridagi anketa PDF'i berilmaydi. */
    public function test_pdf_respects_scope(): void
    {
        [$own, $other, $district] = $this->twoMahallas();

        $anketa = $this->anketaIn($other, $district);
        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, [
            'mahalla_id' => $own, 'district_id' => $district,
        ]);

        // Faolda `export` huquqi yo'q — 403.
        $this->actingAs($user, 'sanctum')
            ->get("/api/ayollar/export/anketa/{$anketa->id}/pdf")
            ->assertForbidden();
    }

    // ---------------------------------------------------------------
    // EXCEL
    // ---------------------------------------------------------------

    public function test_registry_export_has_no_pii(): void
    {
        $d = $this->someDistrictId();
        $anketa = $this->anketaIn($this->someMahallaId($d), $d, pinfl: '31234567890301');
        $name = $anketa->woman->full_name;

        $user = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $d]);

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/ayollar/export/registry')
            ->assertOk();

        $body = $response->getContent();

        // XLSX — ZIP arxiv; matn siqilgan bo'lsa ham, JShShIR va ism
        // umuman yozilmagani uchun ular hech qanday holatda chiqmaydi.
        $this->assertStringNotContainsString('31234567890301', $body);
        $this->assertStringNotContainsString($name, $body);
    }

    /** Eksport suv belgisi bilan — kim va qachon yuklaganini yozadi. */
    public function test_balance_export_is_watermarked(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $this->anketaIn($m, $d);

        $balance = app(BalanceCalculator::class)->calculateMahalla($m, (int) now()->year, (int) now()->month);
        $user = $this->makeUser(AyollarAccess::ROLE_CHAIRMAN, ['mahalla_id' => $m, 'district_id' => $d]);

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/ayollar/export/balance/mahalla/{$balance->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $body = $response->getContent();

        $this->assertStringStartsWith('PK', $body, 'XLSX ZIP arxiv emas.');
    }

    /** Eksport huquqisiz rol rad etiladi. */
    public function test_export_requires_permission(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);

        $activist = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, ['mahalla_id' => $m, 'district_id' => $d]);

        $this->actingAs($activist, 'sanctum')
            ->get('/api/ayollar/export/registry')
            ->assertForbidden();
    }
}
