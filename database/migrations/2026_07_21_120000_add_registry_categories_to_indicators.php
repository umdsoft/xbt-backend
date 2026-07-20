<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Ижтимоий реестр" уч тоифага бўлинади — ҳар бирини алоҳида сақлаймиз.
 *
 * Manba (База 15.07.2026) reestrni shunday ajratadi:
 *   давлат таъминотидаги  - nogironlik, boquvchisini yo'qotganlik va boshqa
 *                           doimiy yordam turlari
 *   камбағал оила         - kambag'allik chegarasidan past
 *   камбағаллик чегарасидаги - chegaraga yaqin, xavf guruhi
 *
 * Shovot bo'yicha: 749 + 1 146 + 550 = 2 445 (jami reestr) — toifalar
 * yig'indisi jamiga aynan to'g'ri keladi.
 *
 * Uchalasi bir ustunga yig'ilsa "kambag'allikka qarshi ish" bilan "doimiy
 * ijtimoiy yordam" farqi yo'qoladi — bular butunlay boshqa chora talab qiladi.
 *
 * Foizlar SAQLANMAYDI: ular sondan hisoblanadi va ikki joyda saqlansa
 * vaqt o'tib bir-biriga zid bo'lib qoladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->table('mahalla_indicators', function (Blueprint $t) {
            $t->unsignedInteger('state_supported_families')->nullable()
                ->comment('Давлат таъминотидаги оилалар');
            $t->unsignedInteger('state_supported_members')->nullable();

            $t->unsignedInteger('poor_members')->nullable()
                ->comment('Камбағал оилалар таркиби');

            $t->unsignedInteger('borderline_families')->nullable()
                ->comment('Камбағаллик чегарасидаги оилалар');
            $t->unsignedInteger('borderline_members')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('master')->table('mahalla_indicators', function (Blueprint $t) {
            $t->dropColumn([
                'state_supported_families', 'state_supported_members',
                'poor_members', 'borderline_families', 'borderline_members',
            ]);
        });
    }
};
