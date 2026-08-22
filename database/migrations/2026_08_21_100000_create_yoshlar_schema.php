<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * YOSHLAR domeni poydevori — `yoshlar` schema + 6 jadval.
 *
 * Qurilish/murojaat naqshi AYNAN: schema jadvallardan OLDIN yaratiladi;
 * faqat PostgreSQL; mavjud jadval -> skip (idempotent, forward-only).
 *
 * district_id/mahalla_id -> master.districts/mahallas, user_id -> auth.users:
 * cross-schema FK YO'Q (uuid + index), chunki schema'lar mustaqil ko'chiriladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::connection('yoshlar')->statement('CREATE SCHEMA IF NOT EXISTS yoshlar');
        $schema = Schema::connection('yoshlar');

        // ---------- Spravochnik ----------

        $this->create($schema, 'sectors', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name_cyr', 300);
            $t->string('name_lat', 300);
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Ikki vertikal (yoshlar + sektoral) YAGONA reyestrda: farq `type` da.
        // parent_id -> tuman tashkiloti o'z viloyat tashkilotiga bog'lanadi.
        $this->create($schema, 'organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type', 20)->index();
            $t->uuid('parent_id')->nullable()->index();
            $t->uuid('district_id')->nullable()->index();
            $t->uuid('sector_id')->nullable()->index();
            $t->string('name_cyr', 500);
            $t->string('name_lat', 500);
            $t->string('short_name', 200)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Foydalanuvchi profili — SCOPE MANBAI. Bu yozuvsiz rol hech narsa ko'rmaydi.
        $this->create($schema, 'staff', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->uuid('org_id')->index();
            $t->string('position', 200)->nullable();
            $t->boolean('can_patronage')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // ---------- Reyestr ----------

        $this->create($schema, 'youth', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('last_name', 120);
            $t->string('first_name', 120);
            $t->string('middle_name', 120)->nullable();
            $t->text('full_name_norm');
            $t->date('birth_date')->index();
            $t->string('gender', 10);
            $t->uuid('district_id')->index();
            $t->uuid('mahalla_id')->index();
            $t->text('address')->nullable();
            $t->string('phone', 30)->nullable();
            // Shifrlangan — `encrypted` cast (Model). Shifrmatn uzunligi o'zgaruvchan.
            $t->text('pinfl')->nullable();
            $t->string('pinfl_hash', 64)->nullable();
            $t->text('passport_series')->nullable();
            $t->text('passport_number')->nullable();
            $t->string('education_status', 30)->default('oqimaydi');
            $t->string('education_place', 300)->nullable();
            $t->string('employment_status', 30)->default('band_emas');
            $t->string('workplace', 300)->nullable();
            $t->boolean('is_neet')->default(false);
            $t->boolean('is_graduate_unemployed')->default(false);
            $t->boolean('in_patronage')->default(false);
            $t->boolean('has_open_case')->default(false);
            $t->boolean('in_youth_book')->default(false);
            $t->boolean('is_entrepreneur')->default(false);
            $t->string('registry_status', 20)->default('active');
            $t->string('verification_status', 20)->default('verified');
            $t->uuid('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->text('reject_reason')->nullable();
            $t->uuid('created_by_org_id')->nullable()->index();
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['registry_status', 'district_id']);
            $t->index('verification_status');
        });

        // ---------- Jurnal ----------

        $this->create($schema, 'audit_log', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->nullable()->index();
            $t->string('action', 60);
            $t->string('entity_type', 40);
            $t->uuid('entity_id')->nullable()->index();
            $t->jsonb('changes')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });

        // Maxfiy maydon ochilishi — HECH QACHON o'chirilmaydi (hisobdorlik).
        $this->create($schema, 'pii_access_log', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->uuid('youth_id')->index();
            $t->string('fields', 120);
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });

        $this->afterTables();
    }

    /**
     * Jadval yaratilgandan keyingi xom SQL: PINFL unikalligi va FIO qidiruvi.
     *
     * `pinfl_hash` partial unique — PINFL ixtiyoriy, NULL qatorlar cheklanmaydi.
     * Trigram indeks `pg_trgm` bo'lsagina; prodda kengaytmaga huquq bo'lmasligi
     * mumkin, shuning uchun xato yutiladi va btree prefiks indeksiga tushamiz.
     */
    private function afterTables(): void
    {
        $db = DB::connection('yoshlar');

        $db->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS youth_pinfl_hash_unique
             ON yoshlar.youth (pinfl_hash) WHERE pinfl_hash IS NOT NULL'
        );

        try {
            $db->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            $db->statement(
                'CREATE INDEX IF NOT EXISTS youth_full_name_trgm
                 ON yoshlar.youth USING gin (full_name_norm gin_trgm_ops)'
            );
        } catch (\Throwable) {
            $db->statement(
                'CREATE INDEX IF NOT EXISTS youth_full_name_prefix
                 ON yoshlar.youth (full_name_norm text_pattern_ops)'
            );
        }

        // Bitta faol xodim — bitta tashkilot. Aks holda scope qaysi tashkilotni
        // olishini bilmay qoladi (ikki xil doira orasida noaniqlik).
        $db->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS staff_active_user_unique
             ON yoshlar.staff (user_id) WHERE is_active'
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
