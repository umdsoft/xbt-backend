<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Geografik doira — kim nimani ko'radi.
 *
 * NEGA GLOBAL SCOPE EMAS: global scope so'rovga JIMGINA qo'shiladi va uni
 * unutish oson emas — lekin uni CHETLAB O'TISH ham oson (`withoutGlobalScope`),
 * va eksport/hisobot kodida aynan shu chetlab o'tish tasodifan yozilardi.
 * Ochiq `apply*()` chaqiruvi kod ko'rigida ko'rinadi: doira qo'llanmagan
 * so'rov darhol ko'zga tashlanadi.
 *
 * MUHIM: eksport ham SHU yerdan o'tadi. Aks holda eksport IDOR'ning eng
 * oson yo'liga aylanardi — ekranda ko'rsatilmagan ma'lumot faylda chiqib
 * ketardi.
 */
class AyollarScope
{
    public function __construct(private readonly AyollarAccess $access) {}

    /**
     * MFY (mahalla) doirasi.
     *
     * `null` — cheklov yo'q (viloyat darajasi).
     * `[]`   — doira yo'q, ya'ni HECH NARSA ko'rinmasin. Bu ataylab: rol
     *          berilgan, lekin `staff` yozuvi yo'q foydalanuvchiga butun
     *          viloyatni ochib qo'yish xavfsizlik teshigi bo'lardi.
     *
     * @return array<int, string>|null
     */
    public function mahallaIds(User $user): ?array
    {
        return match ($this->access->scopeLevel($user)) {
            AyollarAccess::SCOPE_REGION => null,
            AyollarAccess::SCOPE_DISTRICT, AyollarAccess::SCOPE_MAHALLA => null, // tuman/MFY filtri quyida
            default => [],
        };
    }

    /**
     * So'rovga doira qo'shadi.
     *
     * Uch daraja bir metodda: qaysi ustun bo'yicha cheklash rolning doira
     * darajasidan kelib chiqadi. Har model uchun alohida metod yozish
     * (applyWoman/applyAnketa/...) ustunlar bir xil nomlangani uchun
     * takrorlanish bo'lardi.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, User $user): Builder
    {
        $staff = $this->access->staffFor($user);

        return match ($this->access->scopeLevel($user)) {
            AyollarAccess::SCOPE_REGION => $query,

            AyollarAccess::SCOPE_DISTRICT => $staff?->district_id === null
                ? $query->whereRaw('1 = 0')
                : $query->where('district_id', $staff->district_id),

            AyollarAccess::SCOPE_MAHALLA => $staff?->mahalla_id === null
                ? $query->whereRaw('1 = 0')
                : $query->where('mahalla_id', $staff->mahalla_id),

            // Rol yo'q -> hech narsa.
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Aniq hududga kirish huquqi bormi.
     *
     * Route-model binding'dan KEYIN chaqiriladi: `/balances/mahalla/{id}`
     * da `{id}` boshqa tumanniki bo'lishi mumkin va `apply()` bunday
     * bitta-yozuvli so'rovni himoya qilmaydi.
     */
    public function canAccessMahalla(User $user, string $mahallaId, ?string $districtId = null): bool
    {
        $staff = $this->access->staffFor($user);

        return match ($this->access->scopeLevel($user)) {
            AyollarAccess::SCOPE_REGION => true,
            AyollarAccess::SCOPE_DISTRICT => $staff?->district_id !== null
                && $districtId !== null
                && $staff->district_id === $districtId,
            AyollarAccess::SCOPE_MAHALLA => $staff?->mahalla_id === $mahallaId,
            default => false,
        };
    }

    public function canAccessDistrict(User $user, string $districtId): bool
    {
        $staff = $this->access->staffFor($user);

        return match ($this->access->scopeLevel($user)) {
            AyollarAccess::SCOPE_REGION => true,
            AyollarAccess::SCOPE_DISTRICT => $staff?->district_id === $districtId,
            // MFY darajasidagi xodim o'z tumanini KO'RADI (kontekst uchun),
            // lekin uni tahrirlay olmaydi — bu faqat o'qish tekshiruvi.
            AyollarAccess::SCOPE_MAHALLA => $staff?->district_id === $districtId,
            default => false,
        };
    }
}
