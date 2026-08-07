<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Rahbariyat: tuman tanlagich uchun tumanlar ro'yxati.
 *
 * `has_employment` — shu tumanda «Raqamli mahalla» bandlik ma'lumoti bor-yo'qligi
 * (skoring sahifasida qaysi tumanni ochish foydali ekanini ko'rsatadi).
 */
class DistrictListController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // Bandlik ma'lumoti bo'lgan tuman id'lari (mahalla orqali).
        $withEmployment = DB::connection('master')->table('mahalla_indicators as i')
            ->join('mahallas as m', 'm.id', '=', 'i.mahalla_id')
            ->whereNotNull('i.employment_rate')
            ->distinct()->pluck('m.district_id')->all();
        $withEmployment = array_flip($withEmployment);

        $rows = DB::connection('master')->table('districts')
            ->orderBy('sort_order')->orderBy('name_cyr')
            ->get(['id', 'name_cyr', 'soato_code'])
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name_cyr,
                'soato' => $d->soato_code,
                'has_employment' => isset($withEmployment[$d->id]),
            ])
            ->all();

        return response()->json(['districts' => $rows]);
    }
}
