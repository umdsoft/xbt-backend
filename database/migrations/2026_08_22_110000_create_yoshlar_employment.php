<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F3 — band boʻlmagan yoshlarni ishga joylashtirish.
 *
 * ZANJIR (TZ 5.5): ish beruvchi maʼlumoti kiritiladi -> tuman soliq
 * boshqarmasi rasmiy roʻyxatdan oʻtganini tasdiqlaydi -> viloyat soliq
 * boshqarmasi yakunlaydi. Uchalasi oʻtgach «rasman band».
 *
 * NEGA UCH BOSQICH: bandlik koʻrsatkichi hisobotga kiradi, shuning uchun
 * uni faqat ijrochining soʻzi bilan emas, soliq organi tasdigʻi bilan
 * hisobga olamiz.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('yoshlar');

        if ($schema->hasTable('employment_cases')) {
            return;
        }

        $schema->create('employment_cases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('youth_id')->index();
            // Yoshning tumani — soliq organi doirasi shundan aniqlanadi.
            $t->uuid('district_id')->index();

            // Ish beruvchi TIZIMDA hisob ochmaydi (tashqi tashkilot) —
            // shuning uchun uuid emas, nom + INN saqlanadi.
            $t->string('employer_name', 500);
            $t->string('employer_inn', 20)->nullable();
            $t->string('position', 300)->nullable();
            $t->decimal('salary', 14, 2)->nullable();
            $t->date('start_date');
            $t->string('contract_number', 100)->nullable();
            $t->string('document_path', 500)->nullable();

            $t->string('status', 30)->default('yuborildi')->index();

            $t->uuid('submitted_by');
            $t->uuid('submitted_org_id')->nullable()->index();
            $t->timestamp('submitted_at');

            $t->uuid('tax_district_by')->nullable();
            $t->timestamp('tax_district_at')->nullable();
            $t->uuid('tax_province_by')->nullable();
            $t->timestamp('tax_province_at')->nullable();
            $t->text('reject_reason')->nullable();

            $t->timestamps();
            $t->softDeletes();
            $t->index(['district_id', 'status']);
        });

        // Bitta yosh boʻyicha bir vaqtda faqat BITTA ochiq ariza boʻladi:
        // aks holda soliq organida bir odam uchun ikki xil ish joyi navbatga
        // tushib, qaysi biri haqiqiy ekani noaniq qolardi.
        DB::connection('yoshlar')->statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS employment_one_open_per_youth
             ON yoshlar.employment_cases (youth_id)
             WHERE status IN ('yuborildi', 'tuman_soliq_tasdiq') AND deleted_at IS NULL"
        );
    }
};
