<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domen schema'larini yaratadi (agar yo'q bo'lsa):
 *   auth     — markaziy identifikatsiya (SSO): users/systems/user_system_access/sessions
 *   platform — default pgsql ulanishi (DB_SEARCH_PATH=platform,public) uchun
 *   master   — umumiy geo (viloyat/tuman/mahalla/ko'cha)
 *   mahalla  — operatsion domen (honadonlar, profillar)
 * Platform yagona backend sifatida schema'larni O'ZI quradi (fresh deploy).
 * Dev'da allaqachon mavjud -> IF NOT EXISTS bilan xavfsiz.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        foreach (['auth', 'platform', 'master', 'mahalla'] as $schema) {
            DB::connection('pgsql')->statement("CREATE SCHEMA IF NOT EXISTS {$schema}");
        }
    }

    public function down(): void
    {
        // Schema'lar o'chirilmaydi (ma'lumot bor bo'lishi mumkin).
    }
};
