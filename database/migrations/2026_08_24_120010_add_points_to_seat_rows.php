<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `seat_rows.points_json` — qatordagi har bir o'rindiqning ANIQ (x,y) koordinatasi
 * (obyekt-lokal mm, SVG y-past). CAD (avesto.dwg) dan olingan egri/qiya zallar uchun.
 * Bo'lganda frontend shu nuqtalarni chizadi; bo'lmasa eski formula (anchor+rotation+pitch).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasColumn('seat_rows', 'points_json')) {
            return;
        }

        Schema::connection('hr')->table('seat_rows', function (Blueprint $table) {
            $table->jsonb('points_json')->nullable()->after('seat_labels_json');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('hr')->hasColumn('seat_rows', 'points_json')) {
            return;
        }

        Schema::connection('hr')->table('seat_rows', function (Blueprint $table) {
            $table->dropColumn('points_json');
        });
    }
};
