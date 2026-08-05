<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR — CHORA-TADBIR jurnal yozuvining TASDIQLOVCHI FAYLLARI.
 *
 * Har bir bajarilishi yozuvi (action_plan_entries) uchun bir nechta dalil fayli
 * (pdf/rasm/Word/Excel) maxfiy diskda saqlanadi; faqat vakolatli route orqali
 * ochiladi (report_files naqshi). Yozuv o'chirilса — fayllar ham (kod + disk).
 *
 * `advisor` schema. Faqat PostgreSQL; forward-only, idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('advisor');
        if ($schema->hasTable('action_plan_entry_files')) {
            return;
        }

        $schema->create('action_plan_entry_files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('entry_id')->index();
            $t->text('path');                              // maxfiy diskdagi yo'l
            $t->string('original_name');
            $t->string('mime')->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->uuid('uploaded_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        Schema::connection('advisor')->dropIfExists('action_plan_entry_files');
    }
};
