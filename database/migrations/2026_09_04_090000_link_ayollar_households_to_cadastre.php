<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XONADONNI KADASTRGA BOG'LAYDI.
 *
 * Shu paytgacha manzil ERKIN MATN edi: faol «Navoiy ko'chasi, 18-uy»
 * deb yozardi. Uch muammo:
 *
 *   1. Imlo. «Navoiy», «Наво��й», «navoiy k.» — uchtasi uchta boshqa
 *      manzil bo'lib qolardi va bitta xonadon ikki marta yozilardi.
 *   2. Marshrut tuzib bo'lmasdi. «Qaysi uylar qolgan?» degan savolga
 *      javob berish uchun ro'yxat KERAK, matn esa ro'yxat emas.
 *   3. Kadastr allaqachon bor. Viloyat bo'yicha 405 464 bino
 *      (`master.buildings`) va 7 261 ko'cha (`master.streets`) import
 *      qilingan — ular uy raqami, kadastr raqami va koordinatasi bilan.
 *      Ularni ishlatmaslik ma'lumotni qaytadan, yomonroq sifatda
 *      yig'ish degani edi.
 *
 * `address` USTUNI QOLADI. Ikki sabab: (a) kadastrda yo'q xonadon
 * uchrashi mumkin (yangi qurilgan uy, hovli ichidagi alohida xonadon),
 * (b) hisobot va PDF'da tayyor matn kerak. Endi u KO'CHIRMA — tanlangan
 * ko'cha va uy raqamidan yig'iladi.
 *
 * `down()` YO'Q — baza umumiy, forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('ayollar');

        if (! $schema->hasTable('households') || $schema->hasColumn('households', 'street_id')) {
            return;
        }

        $schema->table('households', function (Blueprint $t) {
            // Chet el kaliti QO'YILMAYDI: `master` boshqa sxema va uni
            // Ayollar moduli EGALLAMAYDI. Bino kadastrdan o'chirilsa
            // (masalan buzilgan uy), anketa yo'qolmasligi kerak — u
            // tarixiy hujjat.
            $t->uuid('street_id')->nullable()->index();
            $t->uuid('building_id')->nullable()->index();
            $t->string('house_number', 32)->nullable();
            $t->string('cadastre', 40)->nullable()->index();
        });

        // Bir bino — bir xonadon. Ikki faol bir uyni ikki marta
        // yozib qo'ymasligi uchun. `null` lar cheklanmaydi: kadastrda
        // yo'q xonadonlar shu yerda to'planadi.
        Schema::connection('ayollar')->getConnection()->statement(
            'create unique index if not exists households_building_unique
             on ayollar.households (building_id) where building_id is not null'
        );
    }
};
