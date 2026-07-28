<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MAXFIYLIK — rasm ko'rish AUDIT jurnali. Shaxsiy tasvirlar (ayniqsa uy-ichi)
 * kim, qachon, qaysi IP'dan ko'rilganini qayd etadi. `config('mahalla.privacy.
 * access_log_enabled')` yoqilgan bo'lsa, rasmni uzatuvchi route har ko'rishni
 * shu jadvalga yozadi (append-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('mahalla')->hasTable('photo_access_logs')) {
            return;
        }

        Schema::connection('mahalla')->create('photo_access_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('house_photo_id')->constrained('house_photos')->cascadeOnDelete();
            // user_id — cross-schema (auth.users), shu bois FK YO'Q (zone_observations bilan bir xil).
            $t->uuid('user_id')->nullable()->comment('Ko\'rgan foydalanuvchi (auth.users)');
            $t->string('ip', 45)->nullable();
            $t->timestamp('accessed_at');

            $t->index('house_photo_id');
            $t->index('accessed_at');
        });
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        Schema::connection('mahalla')->dropIfExists('photo_access_logs');
    }
};
