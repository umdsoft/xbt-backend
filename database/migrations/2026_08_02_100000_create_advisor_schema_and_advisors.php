<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR domeni poydevori — `advisor` schema + `advisors` jadvali.
 *
 * Hokim maslahatchilari platformasi (spec §4). Mahalla domeni AYNAN naqsh:
 *   - Schema jadvallardan OLDIN yaratiladi (aks holda public'ga tushardi).
 *   - Faqat PostgreSQL; SQLite (test)/mavjud jadval -> skip (idempotent, forward-only).
 *
 * `advisors` — maslahatchi profili: markaziy auth.users bilan user_id orqali
 * bog'lanadi (FK YO'Q — auth alohida schema, mahalla.users naqshi). district_id
 * master.districts'ga (viloyat maslahatchisi uchun null).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        // Schema jadvaldan OLDIN (create_domain_schemas advisor'ni qamramaydi).
        DB::connection('advisor')->statement('CREATE SCHEMA IF NOT EXISTS advisor');

        if (Schema::connection('advisor')->hasTable('advisors')) {
            return;
        }

        Schema::connection('advisor')->create('advisors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // = auth.users.id. FK yo'q: auth alohida schema (mahalla.users naqshi).
            $table->uuid('user_id')->unique();
            // viloyat | bolinma | tuman (spec §2). App darajasida validatsiya.
            $table->string('level', 20);
            // Tuman maslahatchisi uchun — master.districts. Viloyat/bo'linma: null.
            $table->foreignUuid('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->string('position')->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('level');
            $table->index('district_id');
        });
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        Schema::connection('advisor')->dropIfExists('advisors');
        // Schema o'chirilmaydi (ma'lumot bo'lishi mumkin; forward-only siyosati).
    }
};
