<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * event_attendees endi aniq `seat_id` ga bog'lanadi (seat_row_id o'rniga).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::connection('hr')->table('event_attendees')->delete();

        Schema::connection('hr')->table('event_attendees', function (Blueprint $table) {
            if (! Schema::connection('hr')->hasColumn('event_attendees', 'seat_id')) {
                $table->foreignUuid('seat_id')->nullable()->after('event_group_id')
                    ->constrained('seats')->nullOnDelete();
            }
        });

        if (Schema::connection('hr')->hasColumn('event_attendees', 'seat_row_id')) {
            Schema::connection('hr')->table('event_attendees', function (Blueprint $table) {
                $table->dropForeign(['seat_row_id']);
                $table->dropColumn('seat_row_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('hr')->hasColumn('event_attendees', 'seat_id')) {
            Schema::connection('hr')->table('event_attendees', function (Blueprint $table) {
                $table->dropForeign(['seat_id']);
                $table->dropColumn('seat_id');
            });
        }
    }
};
