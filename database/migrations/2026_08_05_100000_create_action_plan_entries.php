<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR — CHORA-TADBIR bajarilishi JURNALI (arxiv modeli).
 *
 * Tuman «bajarildi» deb tasdiqlamaydi — faqat MA'LUMOT kiritadi (hisobot + sana +
 * ixtiyoriy foiz). Davriy topshiriqlar uchun bir nechta yozuv to'planadi; viloyat
 * butun tarixni (arxiv) ko'radi. Eski `action_plan_progress` (bitta holat) o'rniga.
 *
 * `advisor` schema. Faqat PostgreSQL; forward-only, idempotent.
 * district_id null = viloyat darajasidagi band (viloyat kiritadi).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('advisor');
        if ($schema->hasTable('action_plan_entries')) {
            return;
        }

        $schema->create('action_plan_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('item_id')->index();
            $t->uuid('district_id')->nullable();             // null = viloyat darajasi
            $t->text('report');                              // kiritilgan ma'lumot (majburiy)
            $t->unsignedTinyInteger('progress_percent')->nullable(); // ixtiyoriy
            $t->date('occurred_at');                         // qaysi sanaga oid
            $t->uuid('created_by')->nullable();
            $t->timestamps();

            $t->index(['item_id', 'district_id']);
        });
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        Schema::connection('advisor')->dropIfExists('action_plan_entries');
    }
};
