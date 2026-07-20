<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Ижтимоий реестр"даги оила билан "камбағал оила"ни ажратади.
 *
 * Bular bir narsa emas: reestrga kambag'al oilalardan tashqari davlat
 * ta'minotidagilar (nogironlik, boquvchisini yo'qotganlik) ham kiradi.
 * Shovot bo'yicha reestrda 2 445 oila, ulardan kambag'ali 1 146 ta.
 *
 * Dastlab ikkalasi bitta `poor_families` ustuniga tushib qolgan edi —
 * natijada kambag'allik ikki barobar oshib ko'rinardi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->table('mahalla_indicators', function (Blueprint $t) {
            $t->unsignedInteger('social_registry_families')->nullable()
                ->comment('Ижтимоий реестрдаги оилалар сони');
            $t->unsignedInteger('social_registry_members')->nullable()
                ->comment('Ижтимоий реестрдаги оила аъзолари сони');
            $t->decimal('social_registry_rate', 5, 2)->nullable()
                ->comment('Қамров, % (реестрдаги / жами оила)');
        });

        // Avvalgi import reestr sonini poor_families ga yozgan edi.
        // Ma'lumot yo'qolmasligi uchun to'g'ri ustunga ko'chiriladi.
        Schema::connection('master')->getConnection()->statement(<<<'SQL'
            UPDATE master.mahalla_indicators
               SET social_registry_families = poor_families,
                   social_registry_rate     = poverty_rate,
                   poor_families            = NULL,
                   poverty_rate             = NULL
             WHERE poor_families IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::connection('master')->table('mahalla_indicators', function (Blueprint $t) {
            $t->dropColumn(['social_registry_families', 'social_registry_members', 'social_registry_rate']);
        });
    }
};
