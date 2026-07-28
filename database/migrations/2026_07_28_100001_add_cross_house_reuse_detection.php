<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ALDASH (gaming) himoyasi — HONADONLAR-ARO surat takrori (cross-house reuse).
 *
 * Deputat bir honadon rasmini boshqa honadonga "qayta ishlatib" (yoki ozgina
 * o'zgartirib) hisobotni to'ldirib qo'yishi mumkin. Buni aniqlash uchun har
 * rasmga perceptual hash (dHash, 64-bit -> 16 hex belgi) yoziladi va yangi
 * kuzatuv BOSHQA honadonlarning yaqindagi rasmlariga o'xshab qolsa,
 * `suspected_reuse` bayrog'i qo'yiladi (AI tahlilchi buni o'qiydi).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $conn = Schema::connection('mahalla');

        if ($conn->hasTable('house_photos') && ! $conn->hasColumn('house_photos', 'phash')) {
            $conn->table('house_photos', function (Blueprint $t) {
                // dHash: 64-bit perceptual hash -> 16 belgili hex satr.
                $t->string('phash', 16)->nullable()->after('image_path')
                    ->comment('Perceptual dHash (cross-house reuse aniqlash)');
                $t->index('phash');
            });
        }

        if ($conn->hasTable('zone_observations') && ! $conn->hasColumn('zone_observations', 'suspected_reuse')) {
            $conn->table('zone_observations', function (Blueprint $t) {
                $t->boolean('suspected_reuse')->default(false)->after('is_change')
                    ->comment('Rasm boshqa honadonda ham uchradi (takror gumoni)');
            });
        }
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $conn = Schema::connection('mahalla');

        if ($conn->hasColumn('house_photos', 'phash')) {
            $conn->table('house_photos', fn (Blueprint $t) => $t->dropColumn('phash'));
        }

        if ($conn->hasColumn('zone_observations', 'suspected_reuse')) {
            $conn->table('zone_observations', fn (Blueprint $t) => $t->dropColumn('suspected_reuse'));
        }
    }
};
