<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * HUDUD FILTRI — UUID SHAKLI TEKSHIRILGAN HOLDA.
 *
 * MUAMMO. `district_id` va `mahalla_id` so'rovdan xom holda kelib
 * `where()` ga tushardi. PostgreSQLda bu ustunlar `uuid` turida va
 * `'yoq'` kabi qiymat SQL darajasida XATO beradi — ya'ni 500.
 *
 * Tekshirilganda BARCHA hudud filtri qabul qiladigan endpoint shu
 * holatda edi:
 *
 *     /anketas?district_id=yoq          -> 500
 *     /anketas?mahalla_id=yoq           -> 500
 *     /analytics/needs?district_id=yoq  -> 500
 *     /analytics/daily?district_id=yoq  -> 500
 *     /export/registry?district_id=yoq  -> 500
 *
 * Buni eskirgan havola yoki qo'lda tahrirlangan URL keltirib
 * chiqaradi — hujum shart emas. 500 esa jurnalni to'ldiradi va
 * haqiqiy nosozlikni ko'mib yuboradi.
 *
 * QAROR: yaroqsiz qiymat E'TIBORSIZ QOLDIRILMAYDI, BO'SH natija
 * beradi. Sababi `AnketaFilters::applyNeed()` dagi bilan bir xil:
 * filtr tushunilmasa, «hech narsa» javobi to'g'ri, «hammasi» esa
 * yolg'on — foydalanuvchi qisqarmagan ro'yxatni filtrlangan deb
 * o'qirdi.
 */
final class AreaFilter
{
    /**
     * Filtr berilganmi va u haqiqiy UUIDmi.
     *
     * @return array{0: bool, 1: ?string}  [berilgan, qiymat]
     */
    public static function read(Request $request, string $key): array
    {
        if (! $request->filled($key)) {
            return [false, null];
        }

        $value = $request->string($key)->toString();

        return [true, Str::isUuid($value) ? $value : null];
    }

    /**
     * Filtrni so'rovga qo'llaydi.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>  $query
     * @return bool  natija umuman bo'lishi mumkinmi (yaroqsiz qiymatda `false`)
     */
    public static function apply($query, Request $request, string $key, ?string $column = null): bool
    {
        [$given, $value] = self::read($request, $key);

        if (! $given) {
            return true;
        }

        if ($value === null) {
            // Yaroqsiz UUID — hech narsa qaytmasin.
            $query->whereRaw('false');

            return false;
        }

        $query->where($column ?? $key, $value);

        return true;
    }
}
