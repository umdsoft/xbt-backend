<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mahalla ko'rsatkichlariga ISHSIZLIK va TOMORQA qo'shiladi.
 *
 * Manba: «++2-илова вилоят маҳаллалар» (butun viloyat, 509 mahalla) — bu
 * ustunlar aynan shu faylda mahalla kesimida to'ldirilgan (ishsizlar,
 * tomorqali xonadonlar, tomorqa maydoni). Skoring uchun:
 *   - unemployment_rate → KOI (ishsizlik ulushi yuqori = og'irroq)
 *   - tomorqa (maydon/xonadon) → IPI (bo'sh resurs = imkoniyat)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->table('mahalla_indicators', function (Blueprint $t) {
            if (! Schema::connection('master')->hasColumn('mahalla_indicators', 'unemployed')) {
                $t->unsignedInteger('unemployed')->nullable()->comment('Ишсизлар сони (++2-илова)');
            }
            if (! Schema::connection('master')->hasColumn('mahalla_indicators', 'unemployment_rate')) {
                $t->decimal('unemployment_rate', 5, 2)->nullable()->comment('Ишсизлик ulushi (ишсиз/аҳоли %)');
            }
            if (! Schema::connection('master')->hasColumn('mahalla_indicators', 'tomorqa_households')) {
                $t->unsignedInteger('tomorqa_households')->nullable()->comment('Томорқаси бор хонадонлар');
            }
            if (! Schema::connection('master')->hasColumn('mahalla_indicators', 'tomorqa_area_sotix')) {
                $t->decimal('tomorqa_area_sotix', 12, 2)->nullable()->comment('Умумий томорқа майдони (сотих)');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('master')->table('mahalla_indicators', function (Blueprint $t) {
            $t->dropColumn(['unemployed', 'unemployment_rate', 'tomorqa_households', 'tomorqa_area_sotix']);
        });
    }
};
