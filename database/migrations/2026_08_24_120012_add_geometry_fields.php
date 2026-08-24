<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yangi geometriya modeli: venues'ga bbox/mirror/source, sectors'ga color.
 * Geometriya DWG'dan CHIQARILADI (seats jadvali) — formula YO'Q.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        Schema::connection('hr')->table('venues', function (Blueprint $table) {
            if (! Schema::connection('hr')->hasColumn('venues', 'bbox_json')) {
                $table->jsonb('bbox_json')->nullable()->after('viewbox_json');
            }
            if (! Schema::connection('hr')->hasColumn('venues', 'mirror_axis_json')) {
                $table->jsonb('mirror_axis_json')->nullable()->after('bbox_json');
            }
            if (! Schema::connection('hr')->hasColumn('venues', 'source_file')) {
                $table->string('source_file')->nullable()->after('mirror_axis_json');
            }
        });

        Schema::connection('hr')->table('sectors', function (Blueprint $table) {
            if (! Schema::connection('hr')->hasColumn('sectors', 'color')) {
                $table->string('color', 16)->nullable()->after('label');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->table('venues', function (Blueprint $table) {
            $table->dropColumn(['bbox_json', 'mirror_axis_json', 'source_file']);
        });
        Schema::connection('hr')->table('sectors', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
