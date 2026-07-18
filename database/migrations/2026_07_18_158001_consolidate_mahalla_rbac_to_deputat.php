<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mahalla RBAC konsolidatsiyasi: yagona operatsion rol `deputat` + bir xil
 * ko'cha-scope.
 *   1) auth.user_system_access — mahalla tizimidagi operatsion rollar
 *      (deputat/mahalla-5ligi/masul-xodim) -> `deputat`. `admin` o'zgarmaydi.
 *   2) auth.systems — mahalla nomi rebrand: "Маҳалла мониторинги".
 *   3) mahalla.street_assignments — har bir operatsion userga pilot ko'cha(lar)
 *      (pilot honadonlar joylashgan ko'chalar; Гулистон MFY — Мустақиллик).
 * Idempotent; faqat PostgreSQL. Spatie/mahalla rol strukturasini o'chirmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $auth = DB::connection('auth');
        $mahalla = DB::connection('mahalla');

        $systemId = $auth->table('systems')->where('code', 'mahalla')->value('id');
        if ($systemId === null) {
            return;
        }

        // 1) Operatsion rollarni yagona `deputat` ga birlashtirish (admin — tegilmaydi).
        $auth->table('user_system_access')
            ->where('system_id', $systemId)
            ->whereIn('role', ['deputat', 'mahalla-5ligi', 'masul-xodim'])
            ->update(['role' => 'deputat', 'updated_at' => now()]);

        // 2) Ixtiyoriy rebrand.
        $auth->table('systems')
            ->where('code', 'mahalla')
            ->where('name', 'Маҳалла таъмир мониторинги')
            ->update(['name' => 'Маҳалла мониторинги', 'updated_at' => now()]);

        // 3) Operatsion userlar (mahalla tizimi, admin emas).
        $operationalIds = $auth->table('user_system_access')
            ->where('system_id', $systemId)
            ->where('role', 'deputat')
            ->pluck('user_id')
            ->all();

        if ($operationalIds === []) {
            return;
        }

        // Pilot ko'chalar = pilot honadonlar joylashgan ko'chalar (ishlaydigan scope kafolati).
        $streetIds = $mahalla->table('houses')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('street_id')
            ->all();

        if ($streetIds === []) {
            return;
        }

        foreach ($operationalIds as $userId) {
            foreach ($streetIds as $streetId) {
                // Idempotent: (street_id, user_id) unique -> takror bo'lsa e'tiborsiz.
                $mahalla->table('street_assignments')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'street_id' => $streetId,
                    'user_id' => $userId,
                    'assigned_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Rol konsolidatsiyasi va ko'cha biriktiruvlari qaytarilmaydi (ma'lumot yo'qotmaslik).
    }
};
