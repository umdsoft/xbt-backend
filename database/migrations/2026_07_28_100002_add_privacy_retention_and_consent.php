<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MAXFIYLIK — uy-ICHI rasmlarini saqlash muddati (retention) + honadon roziligi.
 *
 *  - house_photos.pruned_at: rasm FAYLI o'chirilgan vaqt (kuzatuv yozuvi + AI
 *    matni qoladi; faqat shaxsiy tasvir fayli o'chadi). `mahalla:prune-interior-photos`
 *    buyrug'i to'ldiradi.
 *  - houses.monitoring_consent_at / _by: honadon egasining monitoringга roziligi
 *    (scaffolding — hozircha majburiy emas, huquqiy siyosat uchun tayyorlab qo'yiladi).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $conn = Schema::connection('mahalla');

        if ($conn->hasTable('house_photos') && ! $conn->hasColumn('house_photos', 'pruned_at')) {
            $conn->table('house_photos', function (Blueprint $t) {
                $t->timestamp('pruned_at')->nullable()->after('captured_at')
                    ->comment('Rasm fayli maxfiylik bo\'yicha o\'chirilgan vaqt');
                $t->index('pruned_at');
            });
        }

        if ($conn->hasTable('houses') && ! $conn->hasColumn('houses', 'monitoring_consent_at')) {
            $conn->table('houses', function (Blueprint $t) {
                $t->timestamp('monitoring_consent_at')->nullable()
                    ->comment('Honadon egasining monitoringga roziligi vaqti');
                $t->string('monitoring_consent_by')->nullable()
                    ->comment('Rozilikni qayd etgan shaxs/manba');
            });
        }
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $conn = Schema::connection('mahalla');

        if ($conn->hasColumn('house_photos', 'pruned_at')) {
            $conn->table('house_photos', fn (Blueprint $t) => $t->dropColumn('pruned_at'));
        }

        if ($conn->hasColumn('houses', 'monitoring_consent_at')) {
            $conn->table('houses', fn (Blueprint $t) => $t->dropColumn(['monitoring_consent_at', 'monitoring_consent_by']));
        }
    }
};
