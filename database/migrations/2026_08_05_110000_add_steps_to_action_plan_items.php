<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR — CHORA-TADBIR band MEXANIZM BOSQICHLARI (steps).
 *
 * Ba'zi bandlarda «amalga oshirish mexanizmi» bir nechta bosqichли, har biriга
 * ALOHIDA ijro muddati (kalendar sana) kerak — muddat o'tганини kuzatish uchun.
 * steps = [{text, deadline}] (jsonb). Band.deadline = eng kеч bosqich sanаси (umumiy
 * overdue uchun). Eski bandlar (steps=null) — mechanism/deadline matnи bilan ishlайди.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('advisor');
        if ($schema->hasTable('action_plan_items') && ! $schema->hasColumn('action_plan_items', 'steps')) {
            $schema->table('action_plan_items', function (Blueprint $t) {
                $t->jsonb('steps')->nullable()->after('mechanism');
            });
        }
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('advisor');
        if ($schema->hasColumn('action_plan_items', 'steps')) {
            $schema->table('action_plan_items', function (Blueprint $t) {
                $t->dropColumn('steps');
            });
        }
    }
};
