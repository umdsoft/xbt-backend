<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bosqich medialari, haftalik ijro hisoboti va administrator jurnali.
 *
 * NEGA MEDIA ALOHIDA JADVAL: `object_documents` — huquqiy hujjat (shartnoma,
 * ekspertiza xulosasi), u versiyalanadi va sha256 bo'yicha takrorlanmaydi.
 * Surat/video esa dalil: bir bosqichda o'nlab bo'ladi, versiyasi yo'q, lekin
 * olingan SANASI, qaysi bosqichga va qaysi HAFTAGA tegishliligi muhim.
 * Ikkalasini bitta jadvalga tiqish har ikkalasini ham buzardi.
 *
 * NEGA HAFTALIK: ijro bosqichi oylab davom etadi. Oylik grafik faqat pul
 * ko'rsatadi; nazorat organiga esa «shu hafta nima qilindi, nechta ishchi
 * chiqdi, nima to'sqinlik qildi» kerak. Haftalik hisobot ham moderatsiyadan
 * o'tadi — tasdiqlanmagan hisobot arxivda «tasdiqlanmagan» bo'lib qoladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('qurilish');

        /*
         * Haftalik ijro hisoboti. Kalit — (obyekt, yil, hafta): bir haftaga
         * ikkita hisobot bo'lmaydi, aks holda arxivda qaysi biri haqiqiy
         * ekani noaniq bo'lardi.
         */
        if (! $schema->hasTable('object_weekly_reports')) {
            $schema->create('object_weekly_reports', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('object_id')->index();

                $t->smallInteger('year');
                $t->smallInteger('week_no');          // ISO-8601 hafta raqami
                $t->date('period_start');
                $t->date('period_end');

                // Hafta oxiridagi UMUMIY bajarilish va shu haftada qo'shilgani.
                // Ikkalasi ham saqlanadi: birinchisi manba (foydalanuvchi kiritadi),
                // ikkinchisi hosila (oldingi hisobotdan farq) — grafik shundan chiziladi.
                $t->decimal('progress_pct', 5, 2)->default(0);
                $t->decimal('week_progress_pct', 5, 2)->default(0);
                $t->decimal('disbursed_amount', 18, 3)->default(0);

                $t->integer('workers_count')->nullable();
                $t->integer('equipment_count')->nullable();
                $t->text('works_done')->nullable();
                $t->text('problems')->nullable();

                // Bosqich moderatsiyasi bilan bir xil holat lug'ati.
                $t->string('status', 30)->default('qoralama')->index();
                $t->text('rejection_reason')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->uuid('submitted_by')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->uuid('reviewed_by')->nullable();

                $t->uuid('created_by')->nullable();
                $t->timestamps();

                $t->unique(['object_id', 'year', 'week_no']);
                $t->index(['year', 'week_no']);
            });
        }

        /*
         * Surat va video. `stage_code` — qaysi bosqichning dalili;
         * `weekly_report_id` — haftalik hisobotga biriktirilgan bo'lsa.
         * Ikkalasi ham nullable: obyektning umumiy surati ham bo'ladi.
         */
        if (! $schema->hasTable('object_media')) {
            $schema->create('object_media', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('object_id')->index();
                $t->string('stage_code', 40)->nullable()->index();
                $t->uuid('weekly_report_id')->nullable()->index();

                $t->string('kind', 10);                // photo | video
                $t->string('title', 300)->nullable();
                $t->string('stored_path', 500);
                $t->string('original_name', 300);
                $t->string('mime', 120);
                $t->bigInteger('size')->default(0);
                $t->string('sha256', 64)->nullable()->index();

                // Video davomiyligi va rasm o'lchami — ro'yxatda ko'rsatish uchun.
                $t->integer('duration_sec')->nullable();
                $t->integer('width')->nullable();
                $t->integer('height')->nullable();

                // Suratning OLINGAN sanasi — yuklangan sanadan farq qiladi va
                // nazorat uchun aynan shu muhim (kechikkan yuklama dalilni buzmaydi).
                $t->date('taken_at')->nullable()->index();
                $t->boolean('is_cover')->default(false);

                $t->uuid('uploaded_by')->nullable();
                $t->timestamps();

                $t->index(['object_id', 'stage_code']);
            });
        }

        /*
         * Administrator amallari jurnali. `object_audit_log` obyektga bog'langan;
         * hisob ochish yoki dastur yaratish esa obyektga tegishli emas, lekin
         * aynan shular eng nozik amallar — ular ham iz qoldirishi shart.
         */
        if (! $schema->hasTable('admin_audit_log')) {
            $schema->create('admin_audit_log', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('actor_id')->index();
                $t->string('action', 60)->index();     // user_create, program_create, ...
                $t->string('entity', 40)->index();     // user | program | sector | organization
                $t->uuid('entity_id')->nullable()->index();
                $t->string('entity_label', 500)->nullable();
                $t->jsonb('changes')->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamp('created_at')->index();
            });
        }
    }

    public function down(): void
    {
        // Forward-only (domen naqshi).
    }
};
