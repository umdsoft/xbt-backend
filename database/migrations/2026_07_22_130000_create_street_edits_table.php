<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * mahalla.street_edits — rais ko'cha muharriri audit jurnali.
 *
 * Rais o'z mahallasidagi ko'chalarni tuzatadi (nom/birlashtir/o'chir/qo'sh) va
 * uylarni ko'chaga biriktiradi. Har amal kim/qachon/nima bo'yicha yozib qo'yiladi
 * (mavjud building_type_changes na'munasi kabi — javobgarlik va "so'nggi tuzatishlar").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS mahalla.street_edits (
                id           uuid PRIMARY KEY,
                mahalla_id   uuid NOT NULL,
                action       varchar(20) NOT NULL,  -- assign | rename | merge | delete | create
                street_id    uuid,                  -- ta'sirlangan/nishon ko'cha
                building_id  uuid,                  -- assign uchun
                detail       jsonb,                 -- {from,to,name,source_id,count}
                performed_by uuid NOT NULL,
                created_at   timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS street_edits_mahalla_idx ON mahalla.street_edits (mahalla_id, created_at DESC)');
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement('DROP TABLE IF EXISTS mahalla.street_edits');
    }
};
