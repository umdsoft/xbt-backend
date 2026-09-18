<?php

declare(strict_types=1);

namespace Tests\Feature\Ayollar;

use App\Domains\Ayollar\Support\AyollarAccess;

/**
 * «INDIVIDUAL ISH REJASI» EKRANI OCHILSIN.
 *
 * 2026-09-18 da bu sahifa tuman bo'limi hisobida «Ma'lumot
 * yuklanmadi · Server Error» berardi. Sabab bitta qatorda edi:
 *
 *     return response()->json($page + ['names_hidden' => …]);
 *
 * `$page` — obyekt (`LengthAwarePaginator`), massiv emas; PHP 8 da
 * `obyekt + massiv` TypeError beradi.
 *
 * NEGA UNI HECH KIM SEZMADI. `ayollar.workplan.view` faqat ikki
 * rolda bor — «Tuman oila va xotin-qizlar bo'limi» va «Viloyat
 * tahlilchisi». Ikkalasida ham hisob yo'q edi, administratorda esa
 * bu huquq yo'q va menyu bandi unga ko'rinmasdi. Ya'ni sahifa
 * yozilganidan beri BIRORTA marta ochilmagan.
 *
 * Shu sababdan test ro'yxatning MAZMUNINI emas, avvalo ENDPOINT
 * JAVOB BERISHINI qulflaydi.
 */
class WorkPlanTest extends AyollarApiTestCase
{
    public function test_tuman_bolimi_ish_rejasi_royxatini_ochadi(): void
    {
        [, , $district] = $this->twoMahallas();
        $user = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $district]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ayollar/work-plans')
            ->assertOk()
            // SPA aynan shu kalitlarni o'qiydi: `data` va `names_hidden`.
            ->assertJsonStructure(['data', 'total', 'current_page', 'names_hidden']);
    }

    /** Holat filtri bilan ham ochilsin — ekranda «Barchasi» tanlagichi bor. */
    public function test_holat_filtri_bilan_ham_ochiladi(): void
    {
        [, , $district] = $this->twoMahallas();
        $user = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $district]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ayollar/work-plans?status=open')
            ->assertOk()
            ->assertJsonStructure(['data', 'names_hidden']);
    }

    /**
     * Tuman bo'limi ismlarni KO'RADI — bayroq `false` bo'lishi kerak.
     *
     * Bayroq teskari bo'lsa, SPA ekranda «ismlar yashirilgan» degan
     * ogohlantirishni bekordan-bekorga ko'rsatardi.
     */
    public function test_tuman_bolimi_uchun_ismlar_yashirilmaydi(): void
    {
        [, , $district] = $this->twoMahallas();
        $user = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $district]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ayollar/work-plans')
            ->assertOk()
            ->assertJsonPath('names_hidden', false);
    }

    /** MFY faolida bu huquq yo'q — 403, 500 emas. */
    public function test_faol_ish_rejasini_kora_olmaydi(): void
    {
        [$mahalla, , $district] = $this->twoMahallas();
        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, [
            'mahalla_id' => $mahalla,
            'district_id' => $district,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ayollar/work-plans')
            ->assertForbidden();
    }

    /**
     * Yaroqsiz hudud identifikatori 500 BERMASIN.
     *
     * Bu ustunlar PostgreSQLda `uuid` turida va xom matn SQL
     * darajasida xato berardi — modul bo'ylab tuzatilgan naqsh.
     */
    public function test_yaroqsiz_hudud_identifikatori_500_bermaydi(): void
    {
        [, , $district] = $this->twoMahallas();
        $user = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $district]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ayollar/work-plans?mahalla_id=yoq')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    /** Qizil toifa ro'yxati — ekranning ikkinchi tabi. */
    public function test_qizil_toifa_royxati_ochiladi(): void
    {
        [, , $district] = $this->twoMahallas();
        $user = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $district]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ayollar/red-list')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }
}
