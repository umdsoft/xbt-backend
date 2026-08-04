<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR — CHORA-TADBIRLAR: reja EGALIGI (district_id).
 *
 * Har tuman O'ZINING alohida chora-tadbirlar rejasini yaratishi uchun action_plans'ga
 * `district_id` qo'shiladi:
 *   - null  = umumiy (viloyat) reja — 13 tuman bajaradi (avvalgi model);
 *   - <uuid> = shu tumanning O'Z rejasi (faqat o'zi yuritadi, viloyat monitoring qiladi).
 *
 * Forward-only, idempotent, pgsql-guard (advisor schema).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('advisor');
        if ($schema->hasTable('action_plans') && ! $schema->hasColumn('action_plans', 'district_id')) {
            $schema->table('action_plans', function (Blueprint $t) {
                // null = umumiy (viloyat); <uuid> = tuman o'z rejasi (master.districts.id)
                $t->uuid('district_id')->nullable()->after('status')->index();
            });
        }
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('advisor');
        if ($schema->hasColumn('action_plans', 'district_id')) {
            $schema->table('action_plans', function (Blueprint $t) {
                $t->dropColumn('district_id');
            });
        }
    }
};
