<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * XNP TZ v2.0 moderatsiya sikli — `qurilish.object_stages` kengaytirilishi.
 *
 * TZ 2.3: har bosqich 7 holatdan o'tadi va prokuratura tasdig'isiz YOPILMAYDI:
 *   kutilmoqda → ochilgan → qoralama → tasdiqlash_kutilmoqda →
 *   korib_chiqilmoqda → tasdiqlangan;  rad_etilgan → qoralama
 *
 * Bizdagi 5 holat shu 7 taga ko'chiriladi. `talab_etilmaydi` SAQLANADI —
 * TZ'da yo'q, lekin manbadagi 517 obyektda kompleks ekspertiza talab
 * etilmaydi va bu haqiqiy ma'lumot.
 *
 * `tz_stage` — bizning 8 mayda bosqich TZ'ning rasmiy 6 bosqichiga guruhlanadi
 * (farq tahlili 2-bo'lim). UI rasmiy timeline'ni shu ustundan chizadi.
 */
return new class extends Migration
{
    /** Bizning bosqich -> TZ v2.0 rasmiy bosqichi. */
    private const TZ_STAGE = [
        'designer_selection' => 2,
        'design_estimate' => 3,
        'urban_planning' => 3,
        'complex_expertise' => 3,
        'tender' => 4,
        'contract' => 4,
        'execution' => 5,
        'handover' => 6,
    ];

    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('qurilish');

        if (! $schema->hasColumn('object_stages', 'tz_stage')) {
            $schema->table('object_stages', function (Blueprint $t) {
                // TZ rasmiy bosqichi (1..6) — guruhlash uchun.
                $t->smallInteger('tz_stage')->nullable()->index();

                // Moderatsiya izlari: kim yubordi, kim ko'rdi, nega rad etdi.
                $t->timestamp('submitted_at')->nullable();
                $t->uuid('submitted_by')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->uuid('reviewed_by')->nullable();
                $t->text('rejection_reason')->nullable();

                // Bosqichga xos ma'lumot (TZ 16.1 field reference) — G2 fazasida
                // to'ldiriladi, ustun hozir yaratiladi: keyin migratsiya kerak bo'lmasin.
                $t->jsonb('malumot')->nullable();
            });
        }

        $this->backfillTzStage();
        $this->migrateStatuses();
    }

    public function down(): void
    {
        // Forward-only (domen naqshi).
    }

    private function backfillTzStage(): void
    {
        foreach (self::TZ_STAGE as $code => $tz) {
            DB::connection('qurilish')->table('object_stages')
                ->where('stage_code', $code)
                ->whereNull('tz_stage')
                ->update(['tz_stage' => $tz]);
        }
    }

    /**
     * Eski 5 holatni TZ'ning 7 holatiga ko'chiradi.
     *
     * `boshlanmagan` ikkiga bo'linadi: barcha oldingi bosqichlar yopilgan
     * bo'lsa `ochilgan`, aks holda `kutilmoqda`. Strict-sequential kafolati
     * shu yerdan boshlanadi.
     */
    private function migrateStatuses(): void
    {
        $db = DB::connection('qurilish');

        $db->table('object_stages')->where('status', 'yakunlangan')->update(['status' => 'tasdiqlangan']);
        $db->table('object_stages')->where('status', 'jarayonda')->update(['status' => 'qoralama']);
        $db->table('object_stages')->where('status', 'etiroz_bilan_qaytarilgan')->update(['status' => 'rad_etilgan']);

        // `boshlanmagan` -> `ochilgan` faqat oldingi bosqichlar yopilgan bo'lsa.
        // Tartib raqami `stage_order` CTE'sida, SQL bitta o'tishda hisoblaydi.
        $order = [];
        $i = 1;
        foreach (array_keys(self::TZ_STAGE) as $code) {
            $order[] = "('{$code}', {$i})";
            $i++;
        }
        $orderValues = implode(', ', $order);

        $db->statement("
            with stage_order(code, ord) as (values {$orderValues}),
            blocked as (
                select distinct s.object_id, so.ord
                from qurilish.object_stages s
                join stage_order so on so.code = s.stage_code
                where s.status not in ('tasdiqlangan', 'talab_etilmaydi')
            )
            update qurilish.object_stages s
            set status = 'kutilmoqda'
            from stage_order so
            where so.code = s.stage_code
              and s.status = 'boshlanmagan'
              and exists (
                  select 1 from blocked b
                  where b.object_id = s.object_id and b.ord < so.ord
              )
        ");

        // Qolgan `boshlanmagan` lar — oldingisi yopiq, demak ochiq.
        $db->table('object_stages')->where('status', 'boshlanmagan')->update(['status' => 'ochilgan']);
    }
};
