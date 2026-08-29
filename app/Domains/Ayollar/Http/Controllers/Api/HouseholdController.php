<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Models\Household;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Domains\Ayollar\Support\AyollarScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Xonadonlar — oila darajasidagi ma'lumot.
 *
 * Ayol yozuvidan ALOHIDA: manzil va uy-joy holati bir xonadondagi barcha
 * ayollar uchun BITTA. Har ayolda takrorlansa, ular vaqt o'tib bir-biridan
 * farq qilib qolardi va «qaysi manzil to'g'ri?» degan savol tug'ilardi.
 */
class HouseholdController extends Controller
{
    public function __construct(
        private readonly AyollarAccess $access,
        private readonly AyollarScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Household::query()->withCount('women');
        $this->scope->apply($query, $request->user());

        if ($request->filled('mahalla_id')) {
            $query->where('mahalla_id', $request->string('mahalla_id')->toString());
        }

        return response()->json(
            $query->orderBy('address')->paginate(min((int) $request->integer('per_page', 50), 200))
        );
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->access->can($request->user(), 'ayollar.household.manage')) {
            abort(403, 'Xonadon qo‘shishga ruxsat yo‘q.');
        }

        $data = $request->validate([
            'mahalla_id' => ['required', 'uuid'],
            'district_id' => ['required', 'uuid'],
            'address' => ['required', 'string', 'max:500'],
            'residence_type' => ['nullable', 'string', 'max:40'],
            'housing_type' => ['nullable', 'string', 'max:40'],
            'repair_need' => ['nullable', 'string', 'max:40'],
            'in_social_registry' => ['nullable', 'boolean'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);

        if (! $this->scope->canAccessMahalla($request->user(), $data['mahalla_id'], $data['district_id'])) {
            abort(403, 'Bu MFY sizning doirangizda emas.');
        }

        // `client_uuid` bo'yicha idempotent: offline navbat bir xonadonni
        // bir necha marta yuborsa ham bitta yozuv hosil bo'ladi.
        $household = Household::query()->updateOrCreate(
            $data['client_uuid'] ?? null
                ? ['client_uuid' => $data['client_uuid']]
                : ['id' => (string) \Illuminate\Support\Str::uuid()],
            $data + ['created_by' => $request->user()->id],
        );

        return response()->json(['household' => $household], 201);
    }
}
