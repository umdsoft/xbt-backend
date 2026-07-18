<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKAZIY IDENTIFIKATSIYA (SSO) — `auth` schema.
 * Platform butun ekotizim uchun identifikatsiya avtoriteti; boshqa tizimlar
 * (xbt/HR, mahalla, ...) cross-schema o'qiydi.
 *   auth.users               — yagona identifikatsiya (login/parol/ism/telefon)
 *   auth.systems             — tizimlar reyestri (xbt, mahalla, ...)
 *   auth.user_system_access  — kim qaysi tizimga kira oladi (+ o'sha tizimdagi roli)
 *
 * HR/geo kabi tizimga xos ma'lumot markazda EMAS — har tizim o'z schema'sida
 * user_id (auth.users.id) bo'yicha saqlaydi (toza ajratish).
 *
 * xbt loyihasidan ko'chirildi. Guard: mavjud (dev) auth.users bo'lsa — skip
 * (yangi jadval yaratmaydi, mavjud ma'lumotga tegmaydi). Faqat PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Markaziy auth faqat PostgreSQL (schema) muhitida. SQLite testlarda +
        // dev'da (jadval mavjud) skip.
        if (config('database.default') !== 'pgsql' || Schema::connection('auth')->hasTable('users')) {
            return;
        }

        // Schema jadvallardan OLDIN yaratilishi shart (aks holda public'ga tushadi).
        // create_domain_schemas allaqachon yaratadi — bu qatʼiy kafolat (idempotent).
        DB::connection('auth')->statement('CREATE SCHEMA IF NOT EXISTS auth');

        Schema::connection('auth')->create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('login', 100)->unique();
            $table->string('password');
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('auth')->create('systems', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique()->comment('xbt, mahalla, ...');
            $table->string('name');
            $table->string('url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('auth')->create('user_system_access', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('system_id')->constrained('systems')->cascadeOnDelete();
            $table->string('role', 100)->nullable()->comment('O\'sha tizimdagi rol (app o\'zi mapladi)');
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'system_id']);
            $table->index('system_id');
        });
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        Schema::connection('auth')->dropIfExists('user_system_access');
        Schema::connection('auth')->dropIfExists('systems');
        Schema::connection('auth')->dropIfExists('users');
        // Schema'ni o'chirmaymiz (boshqa tizimlar bog'liq bo'lishi mumkin).
    }
};
