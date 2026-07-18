<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Admin;

use App\Domains\Mahalla\Models\Master\Mahalla;
use App\Domains\Mahalla\Models\Master\Street;
use App\Domains\Mahalla\Support\MahallaAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * ADMIN — biriktiruv pickerlari uchun geo (master): mahallalar + ko'chalari + tumani,
 * hamda lavozimlar (mahalla-5ligi) ro'yxati.
 */
class GeoController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $mahallas = Mahalla::query()
            ->with([
                'district:id,name_cyr',
                'streets' => fn ($q) => $q->orderBy('sort_order')->orderBy('name'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name_cyr')
            ->get()
            ->map(fn (Mahalla $m) => [
                'id' => $m->id,
                'name' => $m->name_cyr,
                'district' => $m->district ? [
                    'id' => $m->district->id,
                    'name' => $m->district->name_cyr,
                ] : null,
                'streets' => $m->streets->map(fn (Street $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                ])->values()->all(),
            ])
            ->all();

        return response()->json([
            'mahallas' => $mahallas,
            'positions' => MahallaAccess::positionOptions(),
        ]);
    }
}
