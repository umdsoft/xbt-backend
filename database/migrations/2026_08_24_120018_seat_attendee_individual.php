<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Yakka (individual) o'rindiq biriktirish uchun: attendee guruhsiz ham bo'lishi
 * mumkin (event_group_id nullable). Bitta o'rindiqqa bitta mehmon (partial unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }
        DB::connection('hr')->statement('ALTER TABLE event_attendees ALTER COLUMN event_group_id DROP NOT NULL');
        DB::connection('hr')->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS event_attendees_seat_unique ON event_attendees (event_id, seat_id) WHERE seat_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }
        DB::connection('hr')->statement('DROP INDEX IF EXISTS event_attendees_seat_unique');
        DB::connection('hr')->statement('ALTER TABLE event_attendees ALTER COLUMN event_group_id SET NOT NULL');
    }
};
