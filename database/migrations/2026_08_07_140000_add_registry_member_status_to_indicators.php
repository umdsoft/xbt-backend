<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reyestr oilalari aъzolari holati — ishsiz va nogiron.
 *
 * Manba: «База 15.07.2026 / оила таркиб» (aъzo-darajali, agregatlangan — PII yo'q).
 * registry_unemployed_members = kambag'al oilalardagi ishsiz mehnatga layoqatli
 * aъzolar (hokim uchun aniq «ish bilan ta'minlash» ro'yxati);
 * registry_disabled_members = nogiron aъzolar (ijtimoiy qo'llab-quvvatlash).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->table('mahalla_indicators', function (Blueprint $t) {
            if (! Schema::connection('master')->hasColumn('mahalla_indicators', 'registry_unemployed_members')) {
                $t->unsignedInteger('registry_unemployed_members')->nullable()
                    ->comment('Реестр оилаларидаги ишсиз аъзолар (оила таркиб)');
            }
            if (! Schema::connection('master')->hasColumn('mahalla_indicators', 'registry_disabled_members')) {
                $t->unsignedInteger('registry_disabled_members')->nullable()
                    ->comment('Реестр оилаларидаги ногирон аъзолар (оила таркиб)');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('master')->table('mahalla_indicators', function (Blueprint $t) {
            $t->dropColumn(['registry_unemployed_members', 'registry_disabled_members']);
        });
    }
};
