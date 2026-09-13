<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Support;

use App\Domains\Mahalla\Models\Master\District;
use App\Domains\Mahalla\Models\Master\Mahalla;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Rahbariyat (executive) endpointlari uchun QAMROV hal qiluvchisi.
 *
 * Nima uchun alohida sinf: `executive/*` ostidagi 8 ta controller hozirgacha
 * tumanni umuman tekshirmas edi — u yerga faqat `canSeeAll` rollari kirardi.
 * `tuman` roli qo'shilishi bilan har bir controller "bu user shu tumanni
 * ko'ra oladimi?" degan savolga javob berishi shart bo'ldi. Bu qarorni 8 joyda
 * takrorlash — 8 ta xato qilish imkoniyati; shuning uchun bitta joyda.
 */
final class ExecutiveScope
{
    public function __construct(private readonly MahallaAccess $access) {}

    /**
     * So'ralgan tumanni hal qiladi.
     *
     * `canSeeAll` (admin/viloyat): istalgan tuman; berilmasa konfiguratsiyadagi
     * standart tuman.
     *
     * Tuman bilan cheklangan user: FAQAT o'z tumani. Boshqasi so'ralsa 403.
     * Profilida tuman yo'q bo'lsa ham 403 — standart tumanga TUSHMAYDI.
     */
    public function district(User $user, ?string $requestedId): District
    {
        $scope = $this->access->scopeFor($user);

        if ($scope->canSeeAll) {
            return $requestedId !== null
                ? District::on('master')->findOrFail($requestedId)
                : $this->defaultDistrict();
        }

        $own = $scope->districtId;

        if ($own === null) {
            // `abort(403, ...)` ataylab ishlatilmaydi: Symfony HttpException
            // getCode()'ni har doim 0'ga o'rnatadi (statusni emas), shuning
            // uchun kod HTTP status bilan mos kelishi uchun ochiq beriladi.
            throw new HttpException(403, 'Профилингизда туман кўрсатилмаган.', null, [], 403);
        }

        if ($requestedId !== null && $requestedId !== $own) {
            throw new HttpException(403, 'Бу туман сизнинг қамровингизда эмас.', null, [], 403);
        }

        return District::on('master')->findOrFail($own);
    }

    /**
     * Mahallani hal qiladi va u ruxsat etilgan tuman ichida ekanini tekshiradi.
     *
     * Qamrovdan tashqarisi uchun 404 (403 emas) — begona tumandagi mahalla
     * `id` sining MAVJUDLIGINI ham oshkor qilmaslik uchun.
     */
    public function mahalla(User $user, string $mahallaId): Mahalla
    {
        $scope = $this->access->scopeFor($user);
        $model = Mahalla::on('master')->with('district')->findOrFail($mahallaId);

        if ($scope->canSeeAll) {
            return $model;
        }

        if ($scope->districtId === null || (string) $model->district_id !== $scope->districtId) {
            throw (new ModelNotFoundException)
                ->setModel(Mahalla::class, [$mahallaId]);
        }

        return $model;
    }

    /**
     * Tanlagichda ko'rinadigan tuman id'lari. `null` — cheklov yo'q (hammasi).
     *
     * @return array<int, string>|null
     */
    public function visibleDistrictIds(User $user): ?array
    {
        $scope = $this->access->scopeFor($user);

        if ($scope->canSeeAll) {
            return null;
        }

        return $scope->districtId !== null ? [$scope->districtId] : [];
    }

    private function defaultDistrict(): District
    {
        return District::on('master')
            ->where('soato_code', (string) config('mahalla.executive.default_district_soato'))
            ->firstOrFail();
    }
}
