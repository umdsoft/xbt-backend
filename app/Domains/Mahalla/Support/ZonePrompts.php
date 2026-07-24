<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Support;

/**
 * AI (Claude Vision) uchun AVTOMATIK per-zona promtlar. Har zona uchun nimaga
 * e'tibor berishni belgilaydi va oldingi rasm bilan chuqur solishtirishni so'raydi.
 * Chiqish — qat'iy JSON (deterministik parse uchun).
 */
final class ZonePrompts
{
    /**
     * Har zona bo'yicha nimaga qarash (AI kriteriyasi, Kirill).
     *
     * @var array<string, string>
     */
    private const CRITERIA = [
        'facade' => 'УЙ ФАСАДИ (ташқи кўриниш): девор ва сувоқ ҳолати, бўёқ, том/шифер, эшик-дарвоза, деразалар/ромлар, олд ҳовли ва кўча томон тозалиги. Таъмир ва ободонлаштириш белгилари.',
        'kitchen' => 'ОШХОНА: девор ва пол қопламаси (плитка/сувоқ), ошхона жиҳозлари ва мебели, сув/газ линияси, умумий тозалик ва таъмир ҳолати.',
        'toilet' => 'ҲОЖАТХОНА/САНУЗЕЛ: сантехника (унитаз, раковина), девор/пол қопламаси, сув таъминоти ва канализация, тозалик ва замонавийлаштириш ҳолати.',
        'yard' => 'ТОМОРҚА (ер участкаси): ер ҳолати — ўт/бегона ўт босганми, ташландиқми, тозаланганми, экишга тайёрланган (ҳайдалган/текисланган)ми, экин экилганми, парваришланяптими. Кўкаламзорлаштириш ва деҳқончилик босқичи.',
    ];

    /**
     * Zona holati (status) ta'riflari — AI to'g'ri suggested_status tanlashi uchun.
     */
    private const STATUS_GUIDE = <<<'TXT'
suggested_status қиймати ФАҚАТ қуйидагилардан бири:
- "needs_work": муаммоли, таъмир/иш талаб қилинади (масалан том бузуқ, ер ташландиқ/ўт босган).
- "in_progress": иш бошланган ёки жараёнда (материал келтирилган, қисман бажарилган).
- "completed": иш тугалланган, ободонлаштирилган/таъмирланган.
- "good": дастлабки ҳолати аллақачон яхши, иш талаб этилмайди.
TXT;

    public static function criteria(string $zone): string
    {
        return self::CRITERIA[$zone] ?? 'Уй қисми ҳолати.';
    }

    /**
     * To'liq promt matni. $prevStatus — zonaning joriy holati (bo'lsa),
     * $hasBaseline — solishtirish uchun ASOS (birinchi) rasm bormi.
     */
    public static function build(string $zone, ?string $prevStatus, bool $hasBaseline): string
    {
        $zoneLabel = MahallaZones::zoneLabel($zone) ?? $zone;
        $criteria = self::criteria($zone);
        $guide = self::STATUS_GUIDE;
        $prev = $prevStatus !== null ? (MahallaZones::statusLabel($prevStatus) ?? $prevStatus) : 'номаълум';

        if ($hasBaseline) {
            return <<<TXT
Сен маҳалла хонадонларини мониторинг қилувчи эксперт назоратчисан. Вазифа: бир хонадоннинг "{$zoneLabel}" қисмини кузатиб, АСОСИЙ (дастлабки, биринчи кузатувдаги) ҳолат билан ҲОЗИРГИ ҳолатни солиштириш ва бошланғич ҳолатдан бери БЎЛГАН УМУМИЙ ЎЗГАРИШни (таъмир/ободонлаштириш тараққиётини) аниқлаш.

ЭСЛАТМА: ҳар кузатувда бир зона БИР НЕЧТА РАКУРСдан суратга олиниши мумкин. Барча асосий ракурсларни барча ҳозирги ракурслар билан биргаликда таҳлил қил (турли ракурслар — бир зонанинг ҳар хил бурчаклари, алоҳида ўзгариш эмас).

Нимага эътибор бер: {$criteria}

МУҲИМ ТАМОЙИЛ: расм юкланиши ЎЗГАРИШ дегани ЭМАС. Ўзгариш — фақат ҳолат АСОСГА нисбатан аслида ўзгарган бўлса (масалан: томорқа асосда ўт босган/ташландиқ эди — ҳозир тозаланиб экишга тайёрланган; ёки девор сувоқсиз эди — ҳозир сувоқ қилинган). Агар ҳозирги ҳолат асос билан амалда бир хил бўлса — changed=false.

Зонанинг ҳозирги қайд этилган статуси: {$prev}. Сен ҳозирги суратларга қараб асосдан бери реал ҳолатни баҳолайсан.

{$guide}

Аввало иккала расм БИР ХИЛ ЖОЙ (шу зона)ми — текшир (алдаш/бошқа жойни суратга олишга қарши).

ФАҚАТ қуйидаги JSON форматида, изоҳсиз, жавоб қайтар:
{
  "same_location": true|false,
  "quality_ok": true|false,
  "confidence": 0.0-1.0,
  "cheating_suspected": true|false,
  "before_description": "асосий (дастлабки) ҳолат қисқа тавсифи",
  "after_description": "ҳозирги ҳолат қисқа тавсифи",
  "changed": true|false,
  "change_description": "агар асосдан ўзгарган бўлса — нима ўзгарганини аниқ ёзиб бер; акс ҳолда бўш",
  "suggested_status": "needs_work|in_progress|completed|good",
  "progress_percent": 0-100,
  "reasoning": "қисқа асос"
}
TXT;
        }

        // Birinchi kuzatuv — ASOSNI belgilaydi (solishtirish uchun oldingi rasm yo'q).
        return <<<TXT
Сен маҳалла хонадонларини мониторинг қилувчи эксперт назоратчисан. Бу — "{$zoneLabel}" қисмининг БИРИНЧИ (АСОСИЙ) кузатуви — бу ҳолат кейинги барча кузатувлар учун таққослаш АСОСИ бўлади. Бир нечта ракурс бўлиши мумкин — уларни биргаликда баҳола. Солиштириш учун олдинги расм ЙЎҚ — фақат ҳозирги (бошланғич) ҳолатни баҳола.

Нимага эътибор бер: {$criteria}

{$guide}

ФАҚАТ қуйидаги JSON форматида, изоҳсиз, жавоб қайтар:
{
  "same_location": true,
  "quality_ok": true|false,
  "confidence": 0.0-1.0,
  "cheating_suspected": false,
  "before_description": "",
  "after_description": "ҳозирги ҳолат тавсифи",
  "changed": false,
  "change_description": "",
  "suggested_status": "needs_work|in_progress|completed|good",
  "progress_percent": 0-100,
  "reasoning": "қисқа асос"
}
TXT;
    }
}
