<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * event_allocations endi row_cluster_id ga ishora qiladi (seat_row_id o'rniga).
 * NULL row_cluster_id = butun sektor. Koordinata SAQLAMAYDI.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        // Eski partial indekslar (seat_row_id ga bog'liq) va ma'lumotni tozalash
        DB::connection('hr')->statement('DROP INDEX IF EXISTS event_allocations_row_unique');
        DB::connection('hr')->statement('DROP INDEX IF EXISTS event_allocations_sector_unique');
        DB::connection('hr')->table('event_allocations')->delete();

        Schema::connection('hr')->table('event_allocations', function (Blueprint $table) {
            if (! Schema::connection('hr')->hasColumn('event_allocations', 'row_cluster_id')) {
                $table->foreignUuid('row_cluster_id')->nullable()->after('sector_id')
                    ->constrained('row_clusters')->cascadeOnDelete()->comment('NULL = butun sektor');
            }
        });

        if (Schema::connection('hr')->hasColumn('event_allocations', 'seat_row_id')) {
            Schema::connection('hr')->table('event_allocations', function (Blueprint $table) {
                $table->dropForeign(['seat_row_id']);
                $table->dropColumn('seat_row_id');
            });
        }

        DB::connection('hr')->statement(
            'CREATE UNIQUE INDEX event_allocations_cluster_unique ON event_allocations (event_id, row_cluster_id) WHERE row_cluster_id IS NOT NULL'
        );
        DB::connection('hr')->statement(
            'CREATE UNIQUE INDEX event_allocations_sector_unique ON event_allocations (event_id, sector_id) WHERE row_cluster_id IS NULL'
        );
    }

    public function down(): void
    {
        DB::connection('hr')->statement('DROP INDEX IF EXISTS event_allocations_cluster_unique');
        DB::connection('hr')->statement('DROP INDEX IF EXISTS event_allocations_sector_unique');
        if (Schema::connection('hr')->hasColumn('event_allocations', 'row_cluster_id')) {
            Schema::connection('hr')->table('event_allocations', function (Blueprint $table) {
                $table->dropForeign(['row_cluster_id']);
                $table->dropColumn('row_cluster_id');
            });
        }
    }
};
