<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QURILISH domeni poydevori — `qurilish` schema + 13 jadval.
 *
 * Davlat dasturlari asosidagi qurilish/rekonstruksiya/ta'mirlash ijrosi.
 * Murojaat/advisor naqshi AYNAN: schema jadvallardan OLDIN yaratiladi;
 * faqat PostgreSQL; mavjud jadval -> skip (idempotent, forward-only).
 *
 * district_id/mahalla_id -> master.districts/mahallas (cross-schema FK YO'Q;
 * uuid + index — bulk import uchun xavfsiz). auth.users FK yo'q (auth alohida schema).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::connection('qurilish')->statement('CREATE SCHEMA IF NOT EXISTS qurilish');
        $schema = Schema::connection('qurilish');

        // ---------- Spravochniklar ----------

        // Davlat dasturlari (ПҚ-393, Drayver, Ochiq byudjet, ПҚ-298, DXSh...).
        $this->create($schema, 'programs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name_cyr', 300);
            $t->string('name_lat', 300);
            $t->string('legal_basis', 60)->nullable();
            $t->smallInteger('year')->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Sohalar — obyekt tarmoq turi; boshqarma shu orqali biriktiriladi.
        $this->create($schema, 'sectors', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name_cyr', 300);
            $t->string('name_lat', 300);
            $t->uuid('default_department_org_id')->nullable()->index();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Tashkilotlar — buyurtmachi/loyihachi/pudratchi/boshqarma YAGONA reyestrda.
        // Bitta tashkilot bir vaqtda bir necha rolda bo'lishi mumkin (bayroqlar).
        $this->create($schema, 'organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name_cyr', 500);
            $t->string('name_lat', 500);
            $t->string('short_name', 200)->nullable();
            $t->string('inn', 20)->nullable();
            $t->boolean('is_customer')->default(false);
            $t->boolean('is_designer')->default(false);
            $t->boolean('is_contractor')->default(false);
            $t->boolean('is_department')->default(false);
            $t->uuid('district_id')->nullable()->index();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Import normalizatsiyasi: xom nom -> kanonik tashkilot.
        // alias_norm = tirnoq/bo'shliq/\n tozalangan, translit, UPPER.
        $this->create($schema, 'organization_aliases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('organization_id')->index();
            $t->text('alias_raw');
            $t->text('alias_norm');
            $t->timestamp('created_at')->nullable();
            $t->unique('alias_raw');
            $t->index('alias_norm');
        });

        // ---------- Yadro ----------

        // Obyekt reyestri. `lifecycle='qoralama'` — boshqarma kiritgan, hali
        // dasturga kirmagan (kelajakdagi) obyekt: program_id null bo'lishi mumkin.
        $this->create($schema, 'objects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('external_id', 20)->nullable()->unique();
            $t->uuid('program_id')->nullable()->index();
            $t->uuid('sector_id')->nullable()->index();
            $t->uuid('district_id')->nullable()->index();
            $t->uuid('mahalla_id')->nullable()->index();
            $t->text('name');
            $t->string('work_type', 30)->nullable();
            $t->uuid('customer_org_id')->nullable()->index();
            $t->uuid('designer_org_id')->nullable()->index();
            $t->uuid('contractor_org_id')->nullable()->index();
            $t->uuid('department_org_id')->nullable()->index();
            $t->decimal('limit_amount', 18, 3)->default(0);
            $t->decimal('tender_amount', 18, 3)->default(0);
            $t->decimal('contract_amount', 18, 3)->default(0);
            $t->decimal('disbursed_amount', 18, 3)->default(0);
            $t->decimal('financed_amount', 18, 3)->default(0);
            $t->string('deadline_raw', 60)->nullable();
            $t->date('deadline_date')->nullable()->index();
            $t->smallInteger('deadline_year')->nullable();
            $t->boolean('is_carryover')->default(false);
            $t->string('lifecycle', 20)->default('reja')->index();
            $t->string('current_stage', 30)->nullable()->index();
            $t->boolean('handover_planned')->default(false);
            $t->boolean('handover_done')->default(false);
            $t->text('note')->nullable();
            $t->string('source', 10)->default('manual');
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        // 8 bosqichli holat mashinasi — har obyekt uchun 8 qator.
        $this->create($schema, 'object_stages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('object_id')->index();
            $t->string('stage_code', 30);
            $t->string('status', 30)->default('boshlanmagan');
            $t->date('started_at')->nullable();
            $t->date('completed_at')->nullable();
            $t->uuid('responsible_user_id')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['object_id', 'stage_code']);
        });

        // Oylik ijro grafigi (Yan-Dek) — reja va amaldagi qiymat.
        $this->create($schema, 'object_monthly_plan', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('object_id')->index();
            $t->smallInteger('year');
            $t->smallInteger('month');
            $t->decimal('planned_amount', 18, 3)->default(0);
            $t->decimal('actual_amount', 18, 3)->default(0);
            $t->timestamps();
            $t->unique(['object_id', 'year', 'month']);
        });

        // Hujjatlar — versiyalangan; sha256 bo'yicha disk nusxasi qayta ishlatiladi.
        $this->create($schema, 'object_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('object_id')->index();
            $t->string('stage_code', 30)->nullable();
            $t->string('category', 40)->default('boshqa');
            $t->string('original_name', 500);
            $t->string('stored_path', 500);
            $t->string('mime', 150);
            $t->bigInteger('size');
            $t->char('sha256', 64)->index();
            $t->integer('version')->default(1);
            $t->uuid('uploaded_by')->nullable();
            $t->timestamp('uploaded_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        // O'zgarishlar jurnali — kim, qachon, qaysi maydonni o'zgartirdi.
        $this->create($schema, 'object_audit_log', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('object_id')->index();
            $t->uuid('user_id')->nullable();
            $t->string('action', 40);
            $t->string('field', 60)->nullable();
            $t->text('old_value')->nullable();
            $t->text('new_value')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });

        // ---------- Ta'mirtalab reyestr (2-maqsad) ----------

        // Kelgusi yil/dasturlar uchun ta'mirtalab obyektlar. `promoted_object_id`
        // pipeline'ni yopadi: yozuv real loyihaga aylanganda bog'lanish saqlanadi.
        $this->create($schema, 'repair_needs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('department_org_id')->nullable()->index();
            $t->uuid('sector_id')->nullable()->index();
            $t->uuid('district_id')->nullable()->index();
            $t->uuid('mahalla_id')->nullable()->index();
            $t->text('name');
            $t->text('condition_desc')->nullable();
            $t->decimal('estimated_amount', 18, 3)->nullable();
            $t->smallInteger('target_year')->nullable()->index();
            $t->boolean('funding_source_known')->default(false);
            $t->smallInteger('priority')->default(3);
            $t->string('status', 30)->default('yigilgan')->index();
            $t->uuid('promoted_object_id')->nullable()->index();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        $this->create($schema, 'repair_need_files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('repair_need_id')->index();
            $t->string('category', 40)->default('boshqa');
            $t->string('original_name', 500);
            $t->string('stored_path', 500);
            $t->string('mime', 150);
            $t->bigInteger('size');
            $t->char('sha256', 64)->index();
            $t->integer('version')->default(1);
            $t->uuid('uploaded_by')->nullable();
            $t->timestamp('uploaded_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        // ---------- Kirish nazorati ----------

        // Profil — markaziy auth.users bilan user_id orqali (FK yo'q).
        // organization_id: buyurtmachi/boshqarma scope'ining manbai.
        $this->create($schema, 'profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->unique();
            $t->string('role', 30);
            $t->uuid('organization_id')->nullable()->index();
            $t->uuid('district_id')->nullable()->index();
            $t->string('position', 200)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Har import urinishi — audit uchun.
        $this->create($schema, 'import_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('file_name', 500)->nullable();
            $t->integer('records_count')->default(0);
            $t->uuid('imported_by')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Forward-only (murojaat/advisor naqshi): tushirish qo'lda, dev'da.
        // Ma'lumot yo'qotmaslik uchun bu yerda no-op.
    }

    /** Mavjud jadval -> skip. Migratsiyani xavfsiz qayta yurgizish imkonini beradi. */
    private function create(Builder $schema, string $table, Closure $definition): void
    {
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, $definition);
    }
};
