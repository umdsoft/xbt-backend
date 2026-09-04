<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Services\TabletHome;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Domains\Ayollar\Support\AyollarScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PLANSHET EKRANI — bitta so'rov, butun bosh sahifa.
 *
 * MFY parametri ixtiyoriy: faol uni bermaydi va o'zinikini oladi.
 * Rais yoki tuman xodimi esa boshqa MFY'ni ko'rishi mumkin —
 * doirasi ruxsat bersa.
 */
class TabletController extends Controller
{
    public function __construct(
        private readonly AyollarAccess $access,
        private readonly AyollarScope $scope,
        private readonly TabletHome $home,
    ) {}

    public function home(Request $request): JsonResponse
    {
        if (! $this->access->can($request->user(), 'ayollar.view')) {
            abort(403, 'Ruxsat yo‘q.');
        }

        $requested = $request->string('mahalla_id')->toString();
        $staff = $this->access->staffFor($request->user());

        $mahallaId = $requested !== '' ? $requested : (string) ($staff?->mahalla_id ?? '');

        if ($mahallaId === '') {
            abort(422, 'Sizga MFY biriktirilmagan — administratorga murojaat qiling.');
        }

        $districtId = DB::connection('master')->table('mahallas')
            ->where('id', $mahallaId)->value('district_id');

        if ($districtId === null) {
            abort(404, 'MFY topilmadi.');
        }

        if (! $this->scope->canAccessMahalla($request->user(), $mahallaId, (string) $districtId)) {
            abort(403, 'Bu MFY sizning doirangizda emas.');
        }

        return response()->json($this->home->build(
            $mahallaId,
            (string) $districtId,
            (string) $request->user()->id,
        ));
    }
}
