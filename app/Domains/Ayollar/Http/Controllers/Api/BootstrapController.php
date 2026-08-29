<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Models\Metric;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Domains\Ayollar\Support\Rules;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/api/ayollar/bootstrap` — OFFLINE PAKET.
 *
 * Planshet tarmoq bor paytda shu javobni bir marta oladi va IndexedDB'ga
 * yozadi. Undan keyin anketa to'ldirish uchun tarmoq KERAK EMAS: toifalash
 * qoidalari, MFY ro'yxati va lug'atlar qurilmada.
 *
 * `context` dan FARQI: `context` foydalanuvchiga bog'liq va tez-tez
 * o'zgaradi (badge'lar, doira); `bootstrap` esa deyarli o'zgarmaydigan
 * ma'lumot va uni `ETag` bilan keshlash mumkin. Ikkisini birlashtirish
 * har login'da 500 KB lug'at yuklashni anglatardi.
 *
 * `rules` AYNAN o'sha fayl — frontend uni qayta yozmaydi (promt §14).
 */
class BootstrapController extends Controller
{
    public function __invoke(Request $request, AyollarAccess $access): JsonResponse
    {
        $staff = $access->staffFor($request->user());
        $level = $access->scopeLevel($request->user());

        $mahallas = DB::connection('master')->table('mahallas')->where('is_active', true);

        // Planshet faqat O'Z tumanidagi MFY'larni keshlaydi: 509 MFY'ning
        // hammasi qurilmada kerak emas va u faolning marshrutini
        // chalkashtirardi.
        if ($level !== AyollarAccess::SCOPE_REGION && $staff?->district_id !== null) {
            $mahallas->where('district_id', $staff->district_id);
        }

        $payload = [
            'rules' => Rules::all(),
            'rules_version' => Rules::version(),
            'districts' => DB::connection('master')->table('districts')
                ->orderBy('sort_order')->get(['id', 'name_lat', 'name_cyr', 'code', 'soato_code'])->all(),
            'mahallas' => $mahallas->orderBy('sort_order')
                ->get(['id', 'district_id', 'name_lat', 'name_cyr', 'soato_code'])->all(),
            'metrics' => Metric::query()->orderBy('sort_order')
                ->get(['code', 'name_lat', 'name_cyr', 'category', 'owner_org_code'])->all(),
            'scope' => [
                'level' => $level,
                'district_id' => $staff?->district_id,
                'mahalla_id' => $staff?->mahalla_id,
            ],
        ];

        // ETag — qurilma o'zgarmagan paketni qayta yuklamasin. 3G da bu
        // bir necha yuz kilobayt tejaydi va ilova ochilishini tezlashtiradi.
        $etag = '"'.md5((string) json_encode($payload)).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304)->header('ETag', $etag);
        }

        return response()->json($payload)->header('ETag', $etag);
    }
}
