<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TOPSHIRIQ = RASMIY HUJJAT BANDI.
 *
 * Naqsh manbai: `advisor.action_plan_items` (u ham `hr.ControlPlanItem` dan
 * olingan). Uch domenda bir xil tuzilma boʻlishi tasodif emas — uchalasi
 * ham bitta narsani raqamlashtiradi: hokim imzolagan qogʻoz hujjat bandi.
 *
 * NEGA KERAK: bugungi `tasks` jadvali topshiriqni `title + description +
 * deadline` deb qaraydi. Rasmiy hujjat esa boshqacha tuzilgan:
 *
 *   YOʻL XARITASI jadvali          BAYONNOMA bandi
 *   ─────────────────────          ───────────────
 *   T/r                        →   «6.»                    item_number
 *   Murojaatchining F.I.O      →   —                       applicant_*
 *   Bildirilgan taklif         →   band matni              title
 *   Amalga oshirish mexanizmi  →   «...ekspertizadan
 *                                   oʻtkazsin va...»       mechanism + steps
 *   Ijro muddati               →   «Muddat – 1-noyabr»     deadline_text + deadline
 *   Masʼul ijrochilar          →   «(I.Matchonov)»         responsible_text
 *
 * Bu maydonlar boʻlmasa, tizimdagi topshiriqni qogʻozdagi band bilan
 * solishtirib boʻlmaydi — nazoratchi «6-band qayerda?» deb soʻraganda
 * javob yoʻq. Shuning uchun hujjatning HAR ustuni oʻz ustunini oladi.
 *
 * IKKI MUDDAT USTUNI, ataylab:
 *   `deadline_text` — hujjatdagi ASL soʻz («1 oy muddat», «2026-yil
 *      1-noyabr»). Rasmiy matn oʻzgartirilmasdan koʻrsatiladi.
 *   `deadline` — hisoblangan sana. Muddat oʻtganini faqat shu ustun
 *      biladi; matndan «1 oy» ni har soʻrovda ajratib olib boʻlmaydi.
 *
 * OʻLCHANADIGAN MAQSAD: bayonnomalarda «kamida 10 ta ijtimoiy obyektda»,
 * «kamida 3 ta fermer xoʻjaligi» kabi SON bor. U matn ichida qolsa,
 * ijroni faqat odam oʻqib baholay oladi. Ajratib olinsa — «7/10» boʻlib
 * koʻrinadi va hisobot avtomatik chiqadi.
 *
 * Forward-only, idempotent: mavjud ustun -> oʻtkazib yuboriladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('yoshlar');

        $this->addColumns($schema, 'protocols', [
            // bayonnoma | yol_xaritasi | chora_tadbir — hujjat turi ekranda
            // qaysi ustunlar koʻrsatilishini belgilaydi.
            'type' => fn (Blueprint $t) => $t->string('type', 20)->default('bayonnoma')->index(),
            // «Hokim va yoshlar uchrashuvi» — hujjat qaysi tadbirdan chiqqani.
            'event_title' => fn (Blueprint $t) => $t->string('event_title', 300)->nullable(),
            // Yillik rejalarni yil boʻyicha ajratish uchun.
            'year' => fn (Blueprint $t) => $t->smallInteger('year')->nullable()->index(),
            'file_name' => fn (Blueprint $t) => $t->string('file_name', 300)->nullable(),
        ]);

        $this->addColumns($schema, 'tasks', [
            // Hujjat tuzilishi
            'section_title' => fn (Blueprint $t) => $t->string('section_title', 300)->nullable(),
            'item_number' => fn (Blueprint $t) => $t->string('item_number', 16)->nullable(),
            'sort_order' => fn (Blueprint $t) => $t->integer('sort_order')->default(0),

            // Hujjat mazmuni
            'mechanism' => fn (Blueprint $t) => $t->text('mechanism')->nullable(),
            // [{"no": 1, "text": "...", "deadline": "2026-11-01"|null}]
            'steps' => fn (Blueprint $t) => $t->jsonb('steps')->nullable(),
            'deadline_text' => fn (Blueprint $t) => $t->string('deadline_text', 200)->nullable(),
            'responsible_text' => fn (Blueprint $t) => $t->text('responsible_text')->nullable(),

            // Murojaatchi — yoʻl xaritasida taklif kiritgan YOSH.
            // Reyestrda topilsa `applicant_youth_id`, topilmasa matn: hujjat
            // reyestrdan oldin kelishi mumkin va band shu sababli
            // kiritilmasdan qolmasligi kerak.
            'applicant_youth_id' => fn (Blueprint $t) => $t->uuid('applicant_youth_id')->nullable()->index(),
            'applicant_name' => fn (Blueprint $t) => $t->string('applicant_name', 300)->nullable(),

            // Oʻlchanadigan maqsad
            'target_value' => fn (Blueprint $t) => $t->integer('target_value')->nullable(),
            'target_unit' => fn (Blueprint $t) => $t->string('target_unit', 60)->nullable(),
            'target_done' => fn (Blueprint $t) => $t->integer('target_done')->default(0),
        ]);

        $this->afterColumns();
    }

    private function afterColumns(): void
    {
        $db = DB::connection('yoshlar');

        // Hujjat ichida band raqami TAKRORLANMAYDI.
        //
        // «6-band» ikki marta kiritilsa, ijro hisoboti qaysi biriga
        // tegishli ekani noaniq qoladi va nazoratchi ikkalasini ham
        // yopishi kerak boʻlardi. Baza darajasida toʻsiladi, chunki
        // dastur darajasidagi tekshiruv parallel soʻrovda oʻtib ketadi.
        $db->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS tasks_protocol_item_unique
             ON yoshlar.tasks (protocol_id, item_number)
             WHERE protocol_id IS NOT NULL
               AND item_number IS NOT NULL
               AND deleted_at IS NULL'
        );

        // Hujjat koʻrinishi — bandlar doim shu tartibda oʻqiladi.
        $db->statement(
            'CREATE INDEX IF NOT EXISTS tasks_document_order
             ON yoshlar.tasks (protocol_id, sort_order)'
        );

        // Maqsad manfiy boʻlolmaydi va bajarilgan qismi maqsaddan oshmaydi.
        //
        // «12/10 bajarildi» matematik jihatdan mumkin, ammo hisobotda
        // 120% chiqib, jami foizni buzadi. Ortiqcha ish alohida band
        // sifatida kiritilishi kerak, shu bandning sonini shishirib emas.
        $this->addCheck($db, 'tasks_target_sane', '
            (target_value IS NULL OR target_value > 0)
            AND target_done >= 0
            AND (target_value IS NULL OR target_done <= target_value)
        ');
    }

    /** CHECK cheklovi — mavjud boʻlsa qayta qoʻshilmaydi. */
    private function addCheck(Connection $db, string $name, string $expression): void
    {
        $exists = $db->selectOne(
            'SELECT 1 FROM pg_constraint WHERE conname = ?',
            [$name],
        );

        if ($exists !== null) {
            return;
        }

        $db->statement("ALTER TABLE yoshlar.tasks ADD CONSTRAINT {$name} CHECK ({$expression})");
    }

    /**
     * Ustunlarni faqat mavjud boʻlmaganda qoʻshadi.
     *
     * @param  Builder  $schema
     * @param  array<string, callable(Blueprint): mixed>  $columns
     */
    private function addColumns($schema, string $table, array $columns): void
    {
        if (! $schema->hasTable($table)) {
            return;
        }

        $missing = array_filter(
            $columns,
            fn (string $name) => ! $schema->hasColumn($table, $name),
            ARRAY_FILTER_USE_KEY,
        );

        if ($missing === []) {
            return;
        }

        $schema->table($table, function (Blueprint $t) use ($missing) {
            foreach ($missing as $definition) {
                $definition($t);
            }
        });
    }
};
