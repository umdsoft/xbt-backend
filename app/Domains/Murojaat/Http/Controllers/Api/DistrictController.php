<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Http\Controllers\Api;

use App\Domains\Mahalla\Models\Master\District;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Xorazm 13 tuman/shahar (markaziy master.districts) — tuman picker manbai.
 * SHARTNOMA: [ {"id","name","soato"} ] — sort_order bo'yicha.
 */
class DistrictController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $districts = District::on('master')
            ->orderBy('sort_order')
            ->get(['id', 'name_cyr', 'soato_code'])
            ->map(fn (District $d) => [
                'id' => $d->id,
                'name' => $d->name_cyr,
                'soato' => $d->soato_code,
            ])
            ->all();

        return response()->json($districts);
    }
}
