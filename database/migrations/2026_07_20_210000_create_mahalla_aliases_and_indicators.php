<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mahalla ESKI NOMLARI va DAVRIY KO'RSATKICHLARI.
 *
 * 1) `mahalla_aliases` — mahallalar nomi o'zgaradi (Оқкўл (Бўйрачи) -> Боғбон).
 *    Tashqi fayllar (statistika, kambag'allik ro'yxati) eski yoki yangi nom
 *    bilan kelishi mumkin. Har importda nomni qo'lda qidirmaslik uchun eski
 *    nomlar saqlanadi va moslashtirish avtomatik bo'ladi.
 *
 * 2) `mahalla_indicators` — aholi, xonadon, kambag'allik va dastur belgilari.
 *    DAVR bo'yicha saqlanadi: bu raqamlar yiliga bir necha marta yangilanadi
 *    va rahbarga "o'tgan choraкda qanday edi" degan savol albatta tug'iladi.
 *    Ustiga yozib ketsak, tarix yo'qoladi va uni tiklab bo'lmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->create('mahalla_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mahalla_id');
            $table->string('name_cyr', 160);

            // Solishtirish uchun normallashtirilgan shakl (kichik harf, ў->у,
            // қ->к, "МФЙ" olib tashlangan). Import shu ustun bo'yicha qidiradi.
            $table->string('normalized', 160)->index();

            // `cadastre` — kadastrdan kelgan asl nom
            // `former`   — rasman o'zgartirilgan eski nom
            // `variant`  — imlo varianti
            $table->string('source', 20)->default('former');
            $table->timestamps();

            $table->foreign('mahalla_id')->references('id')->on('master.mahallas')->cascadeOnDelete();
            $table->unique(['mahalla_id', 'normalized']);
        });

        Schema::connection('master')->create('mahalla_indicators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mahalla_id');

            // Hisobot davri boshi (masalan 2026-07-01). Bir mahallaga bir davrda
            // bitta qator.
            $table->date('period');

            // Rasmiy ro'yxat raqamlari. Kadastrdagi bino sonidan FARQ QILADI:
            // kadastr binoni sanaydi, bu ro'yxat xonadonni. Ikkalasi ham kerak.
            $table->integer('population')->nullable();
            $table->integer('households')->nullable();
            $table->integer('families')->nullable();

            $table->integer('poor_families')->nullable();
            $table->decimal('poverty_rate', 5, 2)->nullable();

            // Dastur belgilari
            $table->boolean('is_ogir')->default(false);
            $table->boolean('is_yangi_uzbekiston')->default(false);

            // Qaysi fayldan/kimdan kelgani — raqam shubhali bo'lsa manbani
            // topish uchun.
            $table->string('source', 120)->nullable();
            $table->timestamps();

            $table->foreign('mahalla_id')->references('id')->on('master.mahallas')->cascadeOnDelete();
            $table->unique(['mahalla_id', 'period']);
            $table->index(['period', 'is_ogir']);
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('mahalla_indicators');
        Schema::connection('master')->dropIfExists('mahalla_aliases');
    }
};
