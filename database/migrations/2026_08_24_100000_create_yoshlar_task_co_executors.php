<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HAMKOR IJROCHILAR — bir bandda bir nechta masʼul.
 *
 * Rasmiy hujjatda masʼullar koʻpincha bittadan ortiq:
 *
 *   «Yoshlar ishlari viloyat boshqarmasi (B.Olimov),
 *    viloyat maktabgacha va maktab taʼlimi boshqarmasi (X.Bektemirov)»
 *
 * Ilgari bu faqat MATN edi (`responsible_text`) — ya'ni topshiriq
 * ularning ekraniga umuman chiqmasdi va xabar ham bormasdi. Matn
 * hujjatni to'g'ri ko'rsatardi, lekin ijroni harakatga keltirmasdi.
 *
 * BOSH IJROCHI ALOHIDA QOLADI (`tasks.assigned_org_id`).
 * Sabab: tasdiqlash zanjiri BITTA yuboruvchiga qurilgan
 * (`task_updates_one_open` qisman unikal indeksi). Hisobotni bosh
 * ijrochi yuboradi; hamkorlar topshiriqni KOʻRADI va XABAR OLADI,
 * lekin hisobot yubormaydi.
 *
 * Shu sababli bu jadval FAQAT hamkorlarni saqlaydi, bosh ijrochini emas:
 * ikki manba bir xil maʼlumotni saqlasa, ular vaqt oʻtib bir-biridan
 * uzilib qolardi (`in_patronage` bayrogʻida aynan shunday boʻlgan edi).
 * To'plamlar kesishmaydi — bosh ijrochi hamkor sifatida yozilmaydi.
 *
 * Forward-only, idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('yoshlar');

        if ($schema->hasTable('task_co_executors')) {
            return;
        }

        $schema->create('task_co_executors', function (Blueprint $t) {
            $t->uuid('task_id');
            $t->uuid('org_id')->index();

            // KALIT — JUFTLIKNING OʻZI, alohida `id` ustuni yoʻq.
            //
            // Bu sof bogʻlovchi jadval: bitta tashkilot bir bandga ikki
            // marta biriktirilmaydi, ya'ni juftlik allaqachon unikal.
            // Surrogat kalit qoʻshsak, uni Eloquent'ning `sync()` toʻldira
            // olmasdi (u pivot uchun UUID generatsiya qilmaydi) va ayni
            // paytda hech qanday foyda bermasdi.
            //
            // Kim qachon biriktirgani `yoshlar.audit_log` da — u yerda
            // amalni bajargan foydalanuvchi ham bor.
            $t->primary(['task_id', 'org_id']);
        });

        // Topshiriq o'chirilsa (soft delete emas, haqiqiy o'chirish) —
        // biriktirmalar ham ketadi. Cross-schema FK yo'q, shuning uchun
        // shu schema ichidagi bog'lanish uchun FK qo'yiladi.
        DB::connection('yoshlar')->statement(
            'ALTER TABLE yoshlar.task_co_executors
             ADD CONSTRAINT task_co_executors_task_fk
             FOREIGN KEY (task_id) REFERENCES yoshlar.tasks (id) ON DELETE CASCADE'
        );
    }
};
