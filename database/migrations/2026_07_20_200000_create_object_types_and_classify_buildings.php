<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bino TOIFALARI ma'lumotnomasi + binolarni tasniflash.
 *
 * Kadastr KMZ'sida bino vazifasi `MAQSAD` maydonida ERKIN MATN sifatida
 * yozilgan: Shovotda 1617 obyektga 1080 xil qiymat (katta/kichik harf,
 * ў/у, qo'shtirnoq, imlo xatolari). Shuning uchun toifalar alohida
 * jadvalda saqlanadi va matn kalit so'zlar orqali ularga bog'lanadi.
 *
 * Qoida KODDA emas, BAZADA: yangi tuman yoki yangi toifa qo'shilganda
 * dastur o'zgartirilmaydi, faqat ma'lumotnomaga qator qo'shiladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->create('object_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name_cyr', 120);
            $table->string('name_lat', 120)->nullable();

            /*
             * Tasniflash uchun kalit so'zlar (normallashtirilgan holda).
             * Matn normallashtirilgandan keyin shu ro'yxatdagi biror so'zni
             * o'z ichiga olsa — bino shu toifaga tegishli deb belgilanadi.
             */
            $table->jsonb('keywords')->default(DB::raw("'[]'::jsonb"));

            // Ijtimoiy obyektmi (maktab, bog'cha, shifoxona...) — rahbariyat
            // dashboard'ida faqat shular sanaladi.
            $table->boolean('is_social')->default(false);

            // Bino umuman emas (yer uchastkasi, bo'sh maydon) — hisobga kirmaydi.
            $table->boolean('is_building')->default(true);

            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();

            $table->index(['is_social', 'sort_order']);
        });

        Schema::connection('master')->table('buildings', function (Blueprint $table) {
            $table->uuid('object_type_id')->nullable()->index();
            $table->foreign('object_type_id')->references('id')->on('master.object_types')->nullOnDelete();
        });

        $this->seedTypes();
    }

    public function down(): void
    {
        Schema::connection('master')->table('buildings', function (Blueprint $table) {
            $table->dropForeign(['object_type_id']);
            $table->dropColumn('object_type_id');
        });

        Schema::connection('master')->dropIfExists('object_types');
    }

    /**
     * Boshlang'ich toifalar. Kalit so'zlar NORMALLASHTIRILGAN holda yoziladi:
     * kichik harf, `ў`->`у`, `қ`->`к`, `ғ`->`г`, `ҳ`->`х`, qo'shtirnoqsiz.
     * Shu tufayli "ДЎКОН", "Дукон", "\"Дўкон\"" — uchalasi ham topiladi.
     */
    private function seedTypes(): void
    {
        $now = now();
        $rows = [
            // --- Ijtimoiy obyektlar ---
            ['maktab', 'Мактаб', 'Maktab', ['мактаб', 'урта таълим', 'умумтаълим', 'лицей', 'коллеж', 'интернат'], true, true, 10],
            ['bogcha', 'Болалар боғчаси', 'Bolalar bogʻchasi', ['богча', 'богчаси', 'богчачи', 'мактабгача'], true, true, 20],
            ['kasb_hunar', 'Касб-ҳунар муассасаси', 'Kasb-hunar', ['касб хунар', 'касб-хунар'], true, true, 30],
            ['shifoxona', 'Шифохона / касалхона', 'Shifoxona', ['шифохона', 'касалхона', 'поликлиника'], true, true, 40],
            ['qvp', 'Қишлоқ врачлик пункти', 'QVP', ['врачлик пункт', 'фельдшер', 'тиббиет пункт'], true, true, 50],
            ['dorixona', 'Дорихона', 'Dorixona', ['дорихона'], true, true, 60],
            ['madaniyat', 'Маданият муассасаси', 'Madaniyat', ['маданият', 'кутубхона', 'музей', 'театр', 'клуб'], true, true, 70],
            ['sport', 'Спорт объекти', 'Sport', ['спорт', 'стадион', 'бассейн'], true, true, 80],
            ['mfy_binosi', 'МФЙ биноси', 'MFY binosi', ['мфй бино', 'махалла гузар', 'мфйбино'], true, true, 90],
            ['diniy', 'Диний объект', 'Diniy', ['масжид', 'мачит'], true, true, 100],
            ['mamuriy', 'Маъмурий бино', 'Maʼmuriy', ['мамурий', 'маъмурий', 'хокимият', 'бошкарма', 'инспекция', 'прокуратура'], true, true, 110],

            // --- Ijtimoiy bo'lmagan, lekin bino ---
            ['savdo', 'Савдо объекти', 'Savdo', ['дукон', 'савдо', 'бозор', 'савдо маркази', 'магазин'], false, true, 200],
            ['maishiy', 'Маиший хизмат', 'Maishiy xizmat', ['маиший', 'майиший', 'сартарош', 'чойхона', 'нонвойхона', 'ошхона', 'мехмонхона'], false, true, 210],
            ['ishlab_chiqarish', 'Ишлаб чиқариш', 'Ishlab chiqarish', ['ишлаб чикариш', 'завод', 'фабрика', 'цех', 'устахона', 'кузатувхона'], false, true, 220],
            ['ombor', 'Омборхона', 'Ombor', ['омбор', 'омборхона'], false, true, 230],
            ['chorva', 'Молхона / чорва', 'Chorva', ['молхона', 'товукхона', 'паррандахона', 'иссикхона'], false, true, 240],
            ['infratuzilma', 'Муҳандислик иншооти', 'Infratuzilma', ['антенна', 'мачт', 'сув иншоот', 'козонхона', 'трансформатор', 'газ'], false, true, 250],
            ['qurilmagan', 'Қурилиши тугалланмаган', 'Qurilmagan', ['тугалланмаган', 'тугалламаган', 'тугалланамаган', 'тугалланмагн'], false, true, 260],

            // --- Bino EMAS ---
            ['yer_uchastka', 'Ер участкаси', 'Yer uchastkasi', ['ер учаска', 'ер участка', 'буш ер', 'ер майдон'], false, false, 300],

            // --- Aniqlanmagan ---
            ['boshqa', 'Бошқа / аниқланмаган', 'Boshqa', [], false, true, 900],
        ];

        foreach ($rows as [$code, $cyr, $lat, $keywords, $isSocial, $isBuilding, $sort]) {
            DB::connection('master')->table('object_types')->insert([
                'id' => (string) Illuminate\Support\Str::uuid(),
                'code' => $code,
                'name_cyr' => $cyr,
                'name_lat' => $lat,
                'keywords' => json_encode($keywords, JSON_UNESCAPED_UNICODE),
                'is_social' => $isSocial,
                'is_building' => $isBuilding,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
