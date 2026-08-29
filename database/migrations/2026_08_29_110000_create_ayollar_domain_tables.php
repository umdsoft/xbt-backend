<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AYOLLAR BALANSI — domen jadvallari.
 *
 * MOSLASHTIRISH (promt vs platforma): promtdagi `regions/districts/mahallas`
 * va `users` jadvallari BU YERDA YARATILMAYDI — ular platformada allaqachon
 * bor (`master` schema: 1 viloyat, 13 tuman, 509 MFY; `auth.users`: SSO).
 * Nusxa yaratish ikki xil haqiqat degani bo'lardi: tuman qayta nomlanganda
 * qaysi biri to'g'ri ekani noaniq qolardi.
 *
 * Cross-schema FK YO'Q (`master`/`auth` ga): uuid + index. Schema'lar
 * bir-biridan mustaqil ko'chiriladi va tashqi kalit ko'chirishni bloklardi.
 *
 * `down()` ATAYLAB YO'Q — baza umumiy, 7 boshqa domen ham unda yashaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::connection('ayollar')->statement('CREATE SCHEMA IF NOT EXISTS ayollar');
        $schema = Schema::connection('ayollar');

        // ---------------------------------------------------------------
        // 1. Foydalanuvchi profili — DOIRA MANBAI
        // ---------------------------------------------------------------

        // Markaziy `auth.users` kim ekanini biladi, bu jadval esa u NIMANI
        // ko'rishini. Bu yozuvsiz rol hech narsa ko'rmaydi — «rol bor, lekin
        // doira yo'q» holati ataylab BO'SH natija beradi, xato emas.
        $this->create($schema, 'staff', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->uuid('region_id')->nullable()->index();
            $t->uuid('district_id')->nullable()->index();
            $t->uuid('mahalla_id')->nullable()->index();
            // Tuman idorasi kodi (`iib`, `health`, `tax`, ...) — 8 imzo oqimida
            // kim qaysi qatorni tasdiqlashini shu belgilaydi.
            $t->string('org_code', 40)->nullable()->index();
            $t->string('position', 200)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // ---------------------------------------------------------------
        // 2. Metrikalar lug'ati — BALANS SHAKLLARINING YAGONA MANBAI
        // ---------------------------------------------------------------

        // NEGA JADVAL, KODDA EMAS: hozirgi Excel shakllarida MFY'da bor,
        // tumanda YO'Q 3 qator bor (protection_order, divorced_widowed,
        // social_registry) va agregatsiyada ular yo'qoladi. Shakl koddan
        // generatsiya qilinsa, bu nomuvofiqlik uch joyda takrorlanardi.
        $this->create($schema, 'metric_registry', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 60)->unique();
            $t->string('name_lat', 300);
            $t->string('name_cyr', 300);
            // green | yellow | red | age | meta
            $t->string('category', 20)->index();
            $t->integer('sort_order')->default(0);
            $t->boolean('in_mahalla_form')->default(true);
            $t->boolean('in_district_form')->default(true);
            $t->boolean('in_region_form')->default(true);
            // Qaysi tuman idorasi bu qatorni tasdiqlaydi (8 imzo oqimi).
            $t->string('owner_org_code', 40)->nullable()->index();
            $t->timestamps();
        });

        // ---------------------------------------------------------------
        // 3. Xonadon — oila darajasidagi ma'lumot (bir marta kiritiladi)
        // ---------------------------------------------------------------

        $this->create($schema, 'households', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('mahalla_id')->index();
            $t->uuid('district_id')->index();
            $t->text('address')->nullable();
            $t->string('residence_type', 40)->nullable();
            $t->string('housing_type', 40)->nullable();
            $t->string('repair_need', 40)->nullable();
            $t->boolean('in_social_registry')->default(false);
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->uuid('created_by')->nullable()->index();
            // Offline qurilmadan kelgan yozuvni IDEMPOTENT qabul qilish uchun.
            $t->uuid('client_uuid')->nullable()->unique();
            $t->timestamps();
        });

        // ---------------------------------------------------------------
        // 4. Ayol
        // ---------------------------------------------------------------

        $this->create($schema, 'women', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('household_id')->index();
            $t->uuid('mahalla_id')->index();
            $t->uuid('district_id')->index();
            $t->string('full_name', 300);
            $t->text('full_name_norm');
            $t->date('birth_date')->index();
            // 0_2 | 3_6 | 7_17 | 18_up — tug'ilgan sanadan hosila, lekin
            // SAQLANADI: anketa to'ldirilgan paytdagi guruh keyin yosh
            // o'tishi bilan o'zgarmasligi kerak (balans davri qotib qoladi).
            $t->string('age_group', 10)->index();

            // Shifrlangan maydonlar — `PiiCipher` (AES-256-GCM). Shifrmatn
            // uzunligi o'zgaruvchan, shuning uchun `text`.
            $t->text('pinfl_encrypted')->nullable();
            $t->text('passport_encrypted')->nullable();
            $t->text('phone_encrypted')->nullable();
            // Dublikat tekshiruvi uchun DETERMINISTIK hash (HMAC-SHA256).
            // Shifrmatnning o'zi har safar boshqacha (GCM nonce), shuning
            // uchun u bo'yicha qidirib bo'lmaydi.
            $t->string('pinfl_hash', 64)->nullable();

            $t->timestamp('consent_signed_at')->nullable();
            $t->string('consent_signature_path', 500)->nullable();

            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->uuid('client_uuid')->nullable()->unique();
            $t->timestamps();
            $t->softDeletes();
        });

        // ---------------------------------------------------------------
        // 5. Anketa
        // ---------------------------------------------------------------

        $this->create($schema, 'anketas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('woman_id')->index();
            $t->uuid('mahalla_id')->index();
            $t->uuid('district_id')->index();
            // XOR-08-0142-2026-000731
            $t->string('reg_number', 40)->unique();
            $t->string('form_version', 20)->default('1.0.0');
            $t->string('age_group', 10)->index();

            $t->jsonb('answers')->nullable();

            // green | yellow | incomplete. `red` ALOHIDA TOIFA EMAS —
            // u `anketa_red_flags` da, chunki qizil yashil/sariq ICHIDAN
            // chiqadi va ustma-ust turadi.
            $t->string('category', 20)->nullable()->index();
            $t->string('balance_row', 60)->nullable()->index();
            // Toifa qanday aniqlangani — `CategoryResolver` qadamlari izi.
            // Kartochkadagi «Toifa qanday aniqlandi» bloki shundan chiziladi.
            $t->jsonb('resolution_trace')->nullable();

            // draft | completed | synced | approved | returned
            $t->string('status', 20)->default('draft')->index();

            $t->string('device_id', 100)->nullable()->index();
            $t->timestamp('filled_at')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->decimal('gps_lat', 10, 7)->nullable();
            $t->decimal('gps_lng', 10, 7)->nullable();

            $t->char('qr_token', 6)->nullable()->unique();
            $t->char('qr_hmac', 32)->nullable();

            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            // Offline navbatdan kelgan takroriy yuborishni rad etish uchun.
            $t->uuid('client_uuid')->nullable()->unique();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['mahalla_id', 'status']);
            $t->index(['district_id', 'category']);
        });

        $this->create($schema, 'anketa_red_flags', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('anketa_id')->index();
            $t->string('flag_code', 40)->index();
            $t->integer('source_question');
            $t->timestamp('created_at')->nullable();

            // Bitta anketada bitta belgi BIR marta. Qayta hisoblashda
            // takrorlanib, qizil son sun'iy o'sib ketmasligi uchun.
            $t->unique(['anketa_id', 'flag_code']);
        });

        // ---------------------------------------------------------------
        // 6. Balanslar (materiallashgan)
        // ---------------------------------------------------------------

        foreach (['mahalla', 'district', 'region'] as $level) {
            $this->create($schema, $level.'_balances', function (Blueprint $t) use ($level) {
                $t->uuid('id')->primary();
                $t->uuid($level.'_id')->index();
                $t->smallInteger('period_year');
                $t->smallInteger('period_month');
                // {age_0_2: 102, edu_school: 336, ...} — kalitlar
                // `metric_registry.code` dan.
                $t->jsonb('metrics')->nullable();
                $t->integer('total')->default(0);
                $t->integer('green')->default(0);
                $t->integer('yellow')->default(0);
                $t->integer('red')->default(0);
                // open | closed | approved | returned
                $t->string('status', 20)->default('open')->index();
                $t->timestamp('closed_at')->nullable();
                $t->uuid('closed_by')->nullable();
                $t->text('return_reason')->nullable();
                $t->timestamp('calculated_at')->nullable();
                $t->timestamps();

                // Bir hudud + bir davr = BITTA balans. Aks holda ikki
                // marta yopish ikki qator hosil qilib, tuman yig'indisi
                // jimgina ikki barobar bo'lardi.
                $t->unique([$level.'_id', 'period_year', 'period_month'], $level.'_balance_period_unique');
            });
        }

        // ---------------------------------------------------------------
        // 7. Ko'p imzoli tasdiqlash
        // ---------------------------------------------------------------

        $this->create($schema, 'balance_signatures', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // mahalla | district | region
            $t->string('balance_type', 20)->index();
            $t->uuid('balance_id')->index();
            $t->string('org_code', 40)->index();
            $t->uuid('user_id')->nullable()->index();
            $t->timestamp('signed_at')->nullable();
            $t->text('signature_data')->nullable();
            $t->text('comment')->nullable();
            // pending | signed | returned
            $t->string('status', 20)->default('pending')->index();
            $t->timestamps();

            // Bir balansga bir idora BIR marta imzo qo'yadi.
            $t->unique(['balance_type', 'balance_id', 'org_code'], 'balance_signature_unique');
        });

        // ---------------------------------------------------------------
        // 8. Individual ish rejasi
        // ---------------------------------------------------------------

        $this->create($schema, 'work_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('woman_id')->index();
            $t->uuid('mahalla_id')->index();
            $t->uuid('district_id')->index();
            $t->jsonb('problem_codes')->nullable();
            $t->text('action');
            $t->uuid('responsible_user_id')->nullable()->index();
            $t->date('deadline')->nullable()->index();
            // open | in_progress | done | cancelled
            $t->string('status', 20)->default('open')->index();
            $t->text('result')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
        });

        // ---------------------------------------------------------------
        // 9. Maxfiy maydonga kirish jurnali
        // ---------------------------------------------------------------

        // HECH QACHON o'chirilmaydi. Maskalangan maydonni ochish — hisobdorlik
        // hodisasi: kim, qachon, qaysi ayolning qaysi maydonini ko'rdi.
        $this->create($schema, 'sensitive_access_log', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->uuid('woman_id')->index();
            $t->string('field', 60);
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->timestamp('accessed_at')->nullable()->index();
        });

        // ---------------------------------------------------------------
        // 10. Offline sinxron konfliktlari
        // ---------------------------------------------------------------

        // Konflikt AVTOMATIK hal qilinmaydi (promt §14) — faolga
        // «Qaysi biri to'g'ri?» ekrani ko'rsatiladi. Shu ekran o'qiydigan
        // navbat.
        $this->create($schema, 'sync_conflicts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('entity', 40)->index();
            $t->uuid('entity_id')->index();
            $t->uuid('client_uuid')->nullable()->index();
            $t->string('device_id', 100)->nullable();
            $t->jsonb('server_version')->nullable();
            $t->jsonb('client_version')->nullable();
            // pending | resolved_server | resolved_client | resolved_merge
            $t->string('status', 24)->default('pending')->index();
            $t->uuid('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });

        $this->afterTables();
    }

    /**
     * Jadval yaratilgandan keyingi xom SQL.
     *
     * `pinfl_hash` partial unique — VILOYAT BO'YICHA dublikat tekshiruvi
     * (promt §1.5 talabi). `deleted_at IS NULL` shart: o'chirilgan yozuv
     * yangisini kiritishga to'sqinlik qilmasin, aks holda xato bilan
     * o'chirilgan ayolni qayta kiritib bo'lmasdi.
     *
     * Trigram indeks `pg_trgm` bo'lsagina; prodda kengaytmaga huquq
     * bo'lmasligi mumkin, shuning uchun xato yutiladi va btree prefiks
     * indeksiga tushamiz.
     */
    private function afterTables(): void
    {
        $db = DB::connection('ayollar');

        $db->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS women_pinfl_hash_unique
             ON ayollar.women (pinfl_hash)
             WHERE pinfl_hash IS NOT NULL AND deleted_at IS NULL'
        );

        try {
            $db->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            $db->statement(
                'CREATE INDEX IF NOT EXISTS women_full_name_trgm
                 ON ayollar.women USING gin (full_name_norm gin_trgm_ops)'
            );
        } catch (\Throwable) {
            $db->statement(
                'CREATE INDEX IF NOT EXISTS women_full_name_prefix
                 ON ayollar.women (full_name_norm text_pattern_ops)'
            );
        }

        // Bitta faol xodim — bitta doira. Ikki faol yozuv bo'lsa, scope
        // qaysi MFY'ni olishini bilmay qolardi.
        $db->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS ayollar_staff_active_user_unique
             ON ayollar.staff (user_id) WHERE is_active'
        );

        // Bir ayolda bir davrda BITTA balansga kiradigan anketa. Ikkinchi
        // anketa kiritilsa, birinchisi arxivga o'tishi kerak — bu indeks
        // qoidani bazaga qotirib qo'yadi.
        $db->statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS anketa_active_woman_unique
             ON ayollar.anketas (woman_id)
             WHERE deleted_at IS NULL AND status <> 'returned'"
        );

        // JSONB javoblar bo'yicha qidiruv (ehtiyojlar xaritasi agregatsiyasi).
        $db->statement(
            'CREATE INDEX IF NOT EXISTS anketa_answers_gin
             ON ayollar.anketas USING gin (answers jsonb_path_ops)'
        );
    }

    private function create(\Illuminate\Database\Schema\Builder $schema, string $table, callable $definition): void
    {
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, $definition);
    }
};
