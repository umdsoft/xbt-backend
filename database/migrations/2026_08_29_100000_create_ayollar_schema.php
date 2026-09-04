<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AYOLLAR BALANSI domeni poydevori — `ayollar` schema + jurnal jadvallari.
 *
 * Yoshlar/qurilish naqshi AYNAN: schema jadvallardan OLDIN yaratiladi; faqat
 * PostgreSQL; mavjud jadval -> skip (idempotent, forward-only).
 *
 * `down()` ATAYLAB YO'Q. Baza UMUMIY (bitta `xorazm`/`kbt`), unda 7 ta boshqa
 * domen ham yashaydi. `DROP SCHEMA` yozib qo'yilsa, `migrate:rollback` bir
 * qadamda butun modulni yo'q qilardi — va rollback ko'pincha shoshilinch
 * paytda, ya'ni eng xato qilinadigan paytda chaqiriladi. Orqaga qaytish yo'li
 * qo'lda va ataylab bo'lishi kerak.
 *
 * user_id -> auth.users, district_id -> master.districts: cross-schema FK YO'Q
 * (uuid + index), chunki schema'lar bir-biridan mustaqil ko'chiriladi.
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

        // Umumiy o'zgarishlar jurnali. Domen jadvallaridan OLDIN turadi, chunki
        // birinchi yozuv kiritilishi bilanoq kim/qachon/nimani savoli tug'iladi
        // — jurnalni keyin qo'shish esa o'sha birinchi kunlarni yo'qotish demak.
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
    }

    private function create(Builder $schema, string $table, callable $definition): void
    {
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, $definition);
    }
};
