<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mahalla domenidagi foydalanuvchi PROFILI (mahalla.users) — bir xil id
 * markaziy auth.users bilan. Faqat domenga xos: geo-scope (tuman/mahalla).
 * Identifikatsiya (login/parol) markazda (auth.users). Bu jadval — profil.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('mahalla')->hasTable('users')) {
            return;
        }

        Schema::connection('mahalla')->create('users', function (Blueprint $table) {
            $table->uuid('id')->primary(); // = auth.users.id
            $table->string('name');
            $table->string('login', 100)->unique();
            // Legacy ustun (nullable): identifikatsiya markazda (auth.users). Bu
            // faqat UserManagementController::store yozuv yo'lini qondiradi
            // (auth hash nusxasi). Login/parol tekshiruvi auth.users'da.
            $table->string('password')->nullable();
            $table->string('email')->nullable();
            $table->foreignUuid('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->foreignUuid('mahalla_id')->nullable()->constrained('mahallas')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('district_id');
            $table->index('mahalla_id');
        });
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        Schema::connection('mahalla')->dropIfExists('users');
    }
};
