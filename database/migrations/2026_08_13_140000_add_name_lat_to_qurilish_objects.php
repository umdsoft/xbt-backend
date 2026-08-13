<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `qurilish.objects.name_lat` — obyekt nomining lotin ko'rinishi.
 *
 * Spec 9-bo'lim: «barcha ma'lumot lotin alifbosida». Dastur/soha/tashkilot
 * nomlarida `name_lat` bor edi, obyekt nomlari esa manbadan kirill holicha
 * kelardi va sahifada aralash matn hosil bo'lardi.
 *
 * Kirill nomi `name` da SAQLANADI: eksport va manba bilan solishtirish uchun
 * asl matn kerak, qidiruv esa ikkala ustun bo'yicha ishlaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('qurilish');

        if ($schema->hasColumn('objects', 'name_lat')) {
            return;
        }

        $schema->table('objects', function (Blueprint $t) {
            $t->text('name_lat')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        // Forward-only (domen naqshi).
    }
};
