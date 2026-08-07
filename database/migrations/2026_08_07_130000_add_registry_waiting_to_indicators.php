<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ijtimoiy reyestrда NAVBATДА kutayotgan oilalar (WAITING LIST).
 *
 * Manba: «База 15.07.2026 / оила» (shaxs-darajali reyestr, agregatlangan —
 * PII saqlanmaydi). social_registry_families = reyestrдаги oilalar (asosiy
 * ariza beruvchi), shundan registry_waiting_families = hali tasdiqlanmagan
 * (navbatда) — hokim uchun aniq ustuvor ro'yxat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->table('mahalla_indicators', function (Blueprint $t) {
            if (! Schema::connection('master')->hasColumn('mahalla_indicators', 'registry_waiting_families')) {
                $t->unsignedInteger('registry_waiting_families')->nullable()
                    ->comment('Ижтимоий реестрда навбатда (WAITING LIST) оилалар');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('master')->table('mahalla_indicators', function (Blueprint $t) {
            $t->dropColumn('registry_waiting_families');
        });
    }
};
