<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Xodim oxirgi marta QAYSI qurilmadan kirgani.
 *
 * NEGA KERAK (promt §11 «PIN + qurilma ID»): anketa allaqachon
 * `device_id` bilan saqlanadi, lekin u faqat konflikt aniqlashda
 * ishlatiladi. Bu ustunlar boshqa savolga javob beradi: «bu faol
 * qaysi planshetda ishlaydi va oxirgi marta qachon kirgan?»
 *
 * Faollar monitoringida (promt §10.8) «kim yordamga muhtoj» degan
 * savolga javob berish uchun kerak: bir hafta kirmagan faol —
 * planshet buzilgan yoki xodim almashgan bo'lishi mumkin, va buni
 * anketa sonidan bilib bo'lmaydi (u shunchaki 0 bo'lib qoladi).
 *
 * `down()` YO'Q — baza umumiy, forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('ayollar');

        if (! $schema->hasTable('staff') || $schema->hasColumn('staff', 'last_device_id')) {
            return;
        }

        $schema->table('staff', function (Blueprint $t) {
            $t->string('last_device_id', 100)->nullable()->index();
            $t->string('last_platform', 20)->nullable();
            $t->string('last_app_version', 20)->nullable();
            $t->timestamp('last_seen_at')->nullable()->index();
        });
    }
};
