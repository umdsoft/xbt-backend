<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XONADON YORLIG'I — marshrut ro'yxati uchun.
 *
 * Faol kunlik marshrutda «18-uy» ni ko'radi, lekin qishloqda uy
 * raqami har doim ham yetarli emas: bir hovlida ikki xonadon
 * bo'lishi mumkin va faol qaysi eshikni taqillatishni bilishi kerak.
 *
 * MAXFIYLIK CHEGARASI. Bu ALOHIDA AYOLNING ISMI EMAS — u xonadon
 * yorlig'i («Yusupova oilasi») va uni faolning O'ZI kiritadi:
 * u baribir o'sha eshikda turgan va oilani taniydi.
 *
 * Qat'iy chegaralar:
 *   - faqat O'Z MFY'sidagi faolga qaytariladi
 *   - eksportga, PDF'ga va agregat hisobotlarga TUSHMAYDI
 *   - qizil ro'yxatga hech qanday aloqasi yo'q — u yerda ism
 *     ko'rsatilmaydi va bu qoida o'zgarmaydi
 *
 * Ya'ni «faol yig'adi, ko'rmaydi» qoidasi buzilmaydi: qoida
 * ALOHIDA AYOL haqidagi ma'lumotga tegishli (F.I.Sh., JShShIR,
 * qizil belgilar), xonadon yorlig'i esa navigatsiya vositasi.
 *
 * `down()` YO'Q — forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('ayollar');

        if (! $schema->hasTable('households') || $schema->hasColumn('households', 'family_label')) {
            return;
        }

        $schema->table('households', function (Blueprint $t) {
            $t->string('family_label', 120)->nullable();
        });
    }
};
