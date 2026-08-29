<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Database\Seeders;

use App\Domains\Ayollar\Models\Metric;
use Illuminate\Database\Seeder;

/**
 * Metrikalar lug'ati — MFY, tuman va viloyat shakllarining YAGONA manbai.
 *
 * MUAMMO (promt §4.1): hozirgi Excel shakllarida MFY'da bor, tuman shaklida
 * YO'Q 3 qator bor — `protection_order`, `divorced_widowed`,
 * `social_registry`. Agregatsiyada ular jimgina yo'qoladi: MFY 12 ta himoya
 * orderi ko'rsatadi, tuman esa 0.
 *
 * YECHIM: bitta jadval, uchala shakl ham SHUNDAN generatsiya qilinadi.
 * Yuqoridagi 3 qator uchun `in_district_form` va `in_region_form` = true —
 * ya'ni nomuvofiqlik shu seeder bilan TUZATILADI. Shakl kodda hardcode
 * qilinmaydi (promt §14).
 *
 * Idempotent: `code` unique, mavjud qator yangilanadi.
 */
class MetricRegistrySeeder extends Seeder
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: ?string}>
     *   [code, name_lat, name_cyr, category, owner_org_code]
     */
    private const METRICS = [
        // ---------- JAMI ----------
        ['total', 'Jami xotin-qizlar', 'Жами хотин-қизлар', 'meta', 'family_dept'],

        // ---------- YOSH KESIMI ----------
        // Balans qatoridan ALOHIDA: `age_0_2` yashil qator, `age_grp_0_2`
        // esa yosh kesimi. Ular sonan teng bo'lishi mumkin, lekin ma'nosi
        // boshqa — kesim BARCHA ayollarni qamraydi, qator esa faqat
        // toifalanganini.
        ['age_grp_0_2', '0–2 yosh', '0–2 ёш', 'age', 'family_dept'],
        ['age_grp_3_6', '3–6 yosh', '3–6 ёш', 'age', 'preschool_school'],
        ['age_grp_7_17', '7–17 yosh', '7–17 ёш', 'age', 'preschool_school'],
        ['age_grp_18_up', '18 va undan katta', '18 ва ундан катта', 'age', 'family_dept'],

        // ---------- YASHIL (15) ----------
        ['age_0_2', '0–2 yoshdagilar', '0–2 ёшдагилар', 'green', 'health'],
        ['age_3_6', '3–6 yoshdagilar', '3–6 ёшдагилар', 'green', 'preschool_school'],
        ['edu_postgrad', 'Oliy o‘quv yurtidan keyingi ta’lim', 'Олий ўқув юртидан кейинги таълим', 'green', 'preschool_school'],
        ['edu_higher', 'Oliy ta’limda', 'Олий таълимда', 'green', 'preschool_school'],
        ['edu_professional', 'Professional ta’limda', 'Профессионал таълимда', 'green', 'preschool_school'],
        ['edu_school', 'Maktab o‘quvchilari', 'Мактаб ўқувчилари', 'green', 'preschool_school'],
        ['edu_preschool', 'Maktabgacha ta’lim muassasasida', 'Мактабгача таълим муассасасида', 'green', 'preschool_school'],
        ['emp_gov', 'Davlat tashkilotida rasmiy band', 'Давлат ташкилотида расмий банд', 'green', 'tax'],
        ['emp_private', 'Xususiy sektorda rasmiy band', 'Хусусий секторда расмий банд', 'green', 'tax'],
        ['emp_entrepreneur', 'Tadbirkor', 'Тадбиркор', 'green', 'tax'],
        ['emp_yatt', 'Yakka tartibdagi tadbirkor (YaTT)', 'Якка тартибдаги тадбиркор (ЯТТ)', 'green', 'tax'],
        ['emp_self', 'O‘zini o‘zi band qilgan', 'Ўзини ўзи банд қилган', 'green', 'tax'],
        ['emp_farmer', 'Fermer / dehqon xo‘jaligi', 'Фермер / деҳқон хўжалиги', 'green', 'tax'],
        ['emp_pension', 'Yoshga doir nafaqada', 'Ёшга доир нафақада', 'green', 'family_dept'],
        ['emp_military', 'Harbiy xizmatda', 'Ҳарбий хизматда', 'green', 'family_dept'],

        // ---------- SARIQ (7) ----------
        ['yel_migration', 'Mehnat migratsiyasida', 'Меҳнат миграциясида', 'yellow', 'poverty_reduction'],
        ['yel_jiem', 'JIEM ro‘yxatida', 'ЖИЭМ рўйхатида', 'yellow', 'poverty_reduction'],
        ['yel_incapable', 'Mehnatga layoqatsiz', 'Меҳнатга лаёқатсиз', 'yellow', 'health'],
        ['yel_informal', 'Norasmiy band', 'Норасмий банд', 'yellow', 'poverty_reduction'],
        ['yel_unemployed', 'Ishsiz', 'Ишсиз', 'yellow', 'poverty_reduction'],
        ['yel_homemaker', 'Uy bekasi / bola parvarishida', 'Уй бекаси / бола парваришида', 'yellow', 'family_dept'],
        ['yel_applicant', 'Abituriyent', 'Абитуриент', 'yellow', 'preschool_school'],

        // ---------- QIZIL (13) ----------
        // DIQQAT: bu qatorlar YASHIL/SARIQ ustidan qo'yiladi — ular
        // jamiga QO'SHILMAYDI.
        ['chronic_illness', 'Surunkali kasalligi bor', 'Сурункали касаллиги бор', 'red', 'health'],
        // Quyidagi uchtasi tuman shaklida YO'Q edi — shu yerda tiklandi.
        ['divorced_widowed', 'Ajrashgan yoki beva', 'Ажрашган ёки бева', 'red', 'family_dept'],
        ['social_registry', 'Ijtimoiy reyestrda', 'Ижтимоий реестрда', 'red', 'poverty_reduction'],
        ['protection_order', 'Himoya orderi berilgan', 'Ҳимоя ордери берилган', 'red', 'iib'],

        ['conflict_family', 'Nizoli oila', 'Низоли оила', 'red', 'iib'],
        ['alimony_problem', 'Aliment muammosi', 'Алимент муаммоси', 'red', 'family_dept'],
        ['violence_victim', 'Zo‘ravonlik qurboni', 'Зўравонлик қурбони', 'red', 'iib'],
        ['minor_mother', 'Voyaga yetmagan ona', 'Вояга етмаган она', 'red', 'health'],
        ['probation', 'Probatsiya nazoratida', 'Пробация назоратида', 'red', 'iib'],
        ['prevention_record', 'Profilaktika hisobida', 'Профилактика ҳисобида', 'red', 'iib'],
        ['narcology_record', 'Narkologiya hisobida', 'Наркология ҳисобида', 'red', 'health'],
        ['alien_ideology', 'Yot g‘oyalar ta’sirida', 'Ёт ғоялар таъсирида', 'red', 'iib'],
        ['human_trafficking', 'Odam savdosi qurboni', 'Одам савдоси қурбони', 'red', 'iib'],

        // ---------- QO'SHIMCHA BELGILAR ----------
        ['mtt_covered', 'MTT bilan qamrab olingan (3–6 yosh)', 'МТТ билан қамраб олинган (3–6 ёш)', 'meta', 'preschool_school'],
        ['disability', 'Nogironligi bor', 'Ногиронлиги бор', 'meta', 'health'],
        ['incomplete', 'Anketa to‘liq emas (balansdan tashqarida)', 'Анкета тўлиқ эмас (балансдан ташқарида)', 'meta', 'mahalla_union'],
    ];

    public function run(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        foreach (self::METRICS as $i => [$code, $lat, $cyr, $category, $owner]) {
            Metric::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name_lat' => $lat,
                    'name_cyr' => $cyr,
                    'category' => $category,
                    'sort_order' => ($i + 1) * 10,
                    // Uchala shaklda ham bor — §4.1 dagi nomuvofiqlik shu
                    // yerda tuzatiladi.
                    'in_mahalla_form' => true,
                    'in_district_form' => true,
                    'in_region_form' => true,
                    'owner_org_code' => $owner,
                ],
            );
        }
    }
}
