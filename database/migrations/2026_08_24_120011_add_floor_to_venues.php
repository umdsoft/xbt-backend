<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `venues.floor_json` — obyekt pol/qavat rejasi: zinapoya, yo'lak, plita
 * konturlari (poligonlar massivi, obyekt-lokal mm). CAD (avesto.dwg) dan
 * ezdxf bilan chiqarilgan. Frontend o'rindiqlar ostida chizadi (fon reja).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasColumn('venues', 'floor_json')) {
            return;
        }

        Schema::connection('hr')->table('venues', function (Blueprint $table) {
            $table->jsonb('floor_json')->nullable()->after('stage_json');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('hr')->hasColumn('venues', 'floor_json')) {
            return;
        }

        Schema::connection('hr')->table('venues', function (Blueprint $table) {
            $table->dropColumn('floor_json');
        });
    }
};
