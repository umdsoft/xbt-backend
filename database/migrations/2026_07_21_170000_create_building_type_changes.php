<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кадастр бинолари турини қўлда тузатиш тарихи.
 *
 * Kadastrdagi «maqsad» yozuvi ko'pincha to'liq emas yoki noaniq: Shovot
 * bo'yicha butun tumanda atigi 24 ta ta'lim obyekti qayd etilgan, aslida
 * maktablarning o'zi 40 dan ortiq. Tasnif kalit so'zlar bilan ishlaydi va
 * yozuv bo'lmasa hech narsa qila olmaydi.
 *
 * Mahalla raisi — o'z hududidagi har bir binoni biladigan odam. Shuning
 * uchun tuzatish huquqi unga beriladi, LEKIN faqat mavjud kadastr yozuvini
 * qayta tasniflash: yangi bino o'ylab topa olmaydi.
 *
 * Har o'zgarish yoziladi. Sabablari:
 *   - kim, qachon, nimadan nimaga o'zgartirganini ko'rsatish
 *   - noto'g'ri tuzatishni qaytarish
 *   - tez-tez uchraydigan tuzatishlardan `object_types.keywords` ni
 *     boyitish (bir marta qo'lda, keyin avtomatik)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->create('building_type_changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('building_id')->index();
            $t->uuid('mahalla_id')->nullable()->index();
            $t->uuid('from_type_id')->nullable();
            $t->uuid('to_type_id')->nullable();
            $t->uuid('user_id')->nullable();
            $t->string('note', 500)->nullable();
            $t->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('building_type_changes');
    }
};
