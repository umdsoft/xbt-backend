<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mahalla ko'rsatkichlariga BANDLIK va IXTISOSLASHUV qo'shiladi.
 *
 * Manba: «Ихтисослашув МФЙ» (tuman papkalari + 8) — mahalla kesimida yagona
 * band aholi/ixtisos manbasi. Bu ustunlar «Raqamli mahalla — daromadli oila»
 * skoring dvigateli (KOI/IPI) uchun kirish:
 *   - employment_rate → KOI (band aholi ulushi past = og'irroq)
 *   - specialization_defined → IPI (ixtisos aniq = imkoniyat)
 *
 * Foizlar SAQLANADI (employment_rate) — manbada tayyor keladi va band aholi
 * soni yaxlitlangan bo'lishi mumkin, shuning uchun sondan qayta hisoblamaymiz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->table('mahalla_indicators', function (Blueprint $t) {
            if (! Schema::connection('master')->hasColumn('mahalla_indicators', 'employed_population')) {
                $t->unsignedInteger('employed_population')->nullable()
                    ->comment('Банд аҳоли сони (Ихтисослашув МФЙ)');
            }
            if (! Schema::connection('master')->hasColumn('mahalla_indicators', 'employment_rate')) {
                $t->decimal('employment_rate', 5, 2)->nullable()
                    ->comment('Бандлик даражаси (%) — банд аҳоли/аҳоли');
            }
            if (! Schema::connection('master')->hasColumn('mahalla_indicators', 'specialization')) {
                $t->string('specialization', 500)->nullable()
                    ->comment('Асосий ихтисослашуви (матн)');
            }
            if (! Schema::connection('master')->hasColumn('mahalla_indicators', 'specialization_defined')) {
                $t->boolean('specialization_defined')->nullable()
                    ->comment('Ихтисослашув аниқми (1/0)');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('master')->table('mahalla_indicators', function (Blueprint $t) {
            $t->dropColumn(['employed_population', 'employment_rate', 'specialization', 'specialization_defined']);
        });
    }
};
