<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Models\Metric;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Domains\Ayollar\Support\AyollarScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/api/ayollar/context` — SPA ishga tushganda BIR marta chaqiriladi:
 * foydalanuvchi, roli, ruxsatlari, ko'rish doirasi va spravochniklar.
 *
 * Ma'lumot ikkala alifboda (`name_lat` + `name_cyr`) beriladi: til rejimini
 * SPA tanlaydi, API javobi undan mustaqil (keshlash osonroq).
 *
 * Spravochniklar UMUMIY schema'lardan o'qiladi — modul geo yoki hokimlik
 * nusxasini SAQLAMAYDI. Nusxa bo'lsa, tuman qayta nomlanganida platformada
 * ikki xil haqiqat paydo bo'lardi.
 */
class ContextController extends Controller
{
    public function __invoke(Request $request, AyollarAccess $access, AyollarScope $scope): JsonResponse
    {
        $user = $request->user();
        $role = $access->roleFor($user);
        $staff = $access->staffFor($user);

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'login' => $user->login],
            'role' => $role,
            'role_name' => AyollarAccess::ROLE_NAMES[$role] ?? null,
            'permissions' => $access->permissionsFor($user),
            'scope' => [
                'level' => $access->scopeLevel($user),
                'region_id' => $staff?->region_id,
                'district_id' => $staff?->district_id,
                'mahalla_id' => $staff?->mahalla_id,
                'org_code' => $staff?->org_code,
                'signing_org' => $access->signingOrgCode($user),
                'sees_everything' => $access->seesEverything($user),
            ],
            // Qizil toifadagi ISMLARNI ko'rish — alohida bayroq.
            //
            // Ruxsat ro'yxatida ham bor, lekin SPA uchun ochiq bayroq
            // qulayroq: ro'yxat bo'yicha qidirish o'rniga bitta shart.
            // Server har so'rovda ALOHIDA tekshiradi — bu faqat interfeys
            // uchun.
            'can_see_red_names' => $access->canSeeRedNames($user),
            'reference' => [
                'districts' => DB::connection('master')->table('districts')
                    ->orderBy('sort_order')->get(['id', 'name_lat', 'name_cyr', 'soato_code'])->all(),
                'mahallas' => $this->mahallas($request, $scope),
                'metrics' => Metric::query()->orderBy('sort_order')
                    ->get(['code', 'name_lat', 'name_cyr', 'category', 'owner_org_code', 'sort_order'])->all(),
                'roles' => AyollarAccess::ROLE_NAMES,
                'district_orgs' => \App\Domains\Ayollar\Models\BalanceSignature::DISTRICT_ORGS,
                'mahalla_orgs' => \App\Domains\Ayollar\Models\BalanceSignature::MAHALLA_ORGS,
            ],
        ]);
    }

    /**
     * MFY ro'yxati — DOIRA bo'yicha.
     *
     * 509 MFY'ning hammasini har foydalanuvchiga berish shunchaki og'ir
     * emas: MFY faoli boshqa tumanlarning MFY nomlarini ko'rishi kerak
     * emas. Ro'yxatning o'zi ham ma'lumot.
     *
     * @return array<int, object>
     */
    private function mahallas(Request $request, AyollarScope $scope): array
    {
        $query = DB::connection('master')->table('mahallas')->where('is_active', true);
        $staff = app(AyollarAccess::class)->staffFor($request->user());
        $level = app(AyollarAccess::class)->scopeLevel($request->user());

        if ($level === AyollarAccess::SCOPE_DISTRICT && $staff?->district_id !== null) {
            $query->where('district_id', $staff->district_id);
        } elseif ($level === AyollarAccess::SCOPE_MAHALLA && $staff?->district_id !== null) {
            // MFY xodimi o'z TUMANIDAGI ro'yxatni ko'radi: dublikat
            // topilganda «bu ayol 12-MFYda» xabari mazmunli bo'lishi uchun
            // qo'shni MFY nomini bilishi kerak.
            $query->where('district_id', $staff->district_id);
        }

        return $query->orderBy('sort_order')
            ->get(['id', 'district_id', 'name_lat', 'name_cyr', 'soato_code'])
            ->all();
    }
}
