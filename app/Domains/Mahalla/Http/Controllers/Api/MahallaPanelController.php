<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api;

use App\Domains\Mahalla\Support\MahallaAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mahalla-ga bog'langan panel controllerlari (rais / hokim yordamchisi) uchun asos.
 *
 * Qamrov (mahalla_id) bir joydan olinadi — `MahallaAccess::scopeFor()`, hech qachon
 * reqestdan. "Mahalla ko'rsatilmagan" (409) javobi ham shu yerda — ilgari har bir
 * controllerda takrorlanardi (DRY).
 */
abstract class MahallaPanelController extends Controller
{
    public function __construct(protected readonly MahallaAccess $access)
    {
    }

    /** Joriy foydalanuvchi profilidagi mahalla id (yo'q bo'lsa null). */
    protected function mahallaId(Request $request): ?string
    {
        return $this->access->scopeFor($request->user())->mahallaId;
    }

    /** Mahalla id — majburiy: yo'q bo'lsa 409 bilan to'xtatadi. */
    protected function requireMahallaId(Request $request): string
    {
        $id = $this->mahallaId($request);
        abort_if($id === null, 409, 'Профилингизда маҳалла кўрсатилмаган. Администраторга мурожаат қилинг.');

        return $id;
    }

    /** Mahalla ko'rsatilmagan holati uchun standart 409 javobi. */
    protected function noMahalla(): JsonResponse
    {
        return response()->json([
            'message' => 'Профилингизда маҳалла кўрсатилмаган. Администраторга мурожаат қилинг.',
        ], 409);
    }
}
