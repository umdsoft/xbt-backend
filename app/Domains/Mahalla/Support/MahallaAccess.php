<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Support;

use App\Domains\Mahalla\Models\MahallaProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * mahalla domeni RBAC — markaziy identifikatsiyadan rol/ruxsat/geo-scope hal qiladi.
 * Rol = user_system_access.role ('mahalla' tizimi); ruxsat = kod xaritasi;
 * geo-scope = MahallaProfile (mahalla.users) + street_assignments.
 */
class MahallaAccess
{
    public const SYSTEM_CODE = 'mahalla';

    /**
     * Yagona operatsion rol `deputat` — to'liq monitoring (yuklash ham), lekin faqat
     * biriktirilgan ko'chalar doirasida. `admin` — super-admin (hammasini ko'radi).
     * mahalla-5ligi/masul-xodim endi `deputat` ga birlashtirildi.
     *
     * @var array<string, array<int, string>>
     */
    private const PERMISSIONS = [
        'admin' => ['*'],
        // Raҳbariyat: FAQAT ko'rish. photos.upload va `*` ataylab yo'q.
        'viloyat' => ['dashboard.view', 'reports.view', 'houses.view', 'analyses.view'],
        'deputat' => ['houses.view', 'photos.view', 'photos.upload', 'analyses.view', 'dashboard.view'],
        /*
         * Маҳалла раиси — ўз маҳалласининг ҲАММАСИНИ кўради.
         *
         * `deputat` dan farqi: ko'chalar bilan cheklanmaydi. Rais mahalladagi
         * har bir hovli va binoni biladigan odam, shuning uchun qamrovi butun
         * mahalla.
         *
         * `buildings.classify` — kadastr binosining turini tuzatish. Bu unga
         * beriladi, chunki kadastr yozuvi to'liq emas va uni haqiqat bilan
         * solishtira oladigan yagona odam shu. Lekin FAQAT qayta tasniflash:
         * yangi bino qo'sha olmaydi.
         */
        'rais' => [
            'houses.view', 'photos.view', 'photos.upload', 'analyses.view',
            'dashboard.view', 'buildings.classify',
        ],
    ];

    /**
     * Rahbariyat dashboard'ini ko'ra oladigan rollar.
     *
     * @var array<int, string>
     */
    public const VIEWER_ROLES = ['admin', 'viloyat'];

    /**
     * Mahalla-5ligi lavozimlari (tavsifiy yorliq — huquqqa ta'sir qilmaydi).
     * Kod => ko'rsatiladigan nom (Kirill).
     *
     * @var array<string, string>
     */
    public const POSITIONS = [
        'rais' => 'Маҳалла раиси',
        'hokim_yordamchisi' => 'Ҳоким ёрдамчиси',
        'yoshlar' => 'Ёшлар етакчиси',
        'xotin_qizlar' => 'Хотин-қизлар фаоли',
        'profilaktika' => 'Профилактика инспектори',
    ];

    /**
     * Lavozimlar ro'yxati API/picker uchun: [['code' => ..., 'name' => ...], ...].
     *
     * @return array<int, array{code: string, name: string}>
     */
    public static function positionOptions(): array
    {
        $out = [];
        foreach (self::POSITIONS as $code => $name) {
            $out[] = ['code' => $code, 'name' => $name];
        }

        return $out;
    }

    /**
     * Lavozim kodidan ko'rsatiladigan nom (yoki null).
     */
    public static function positionLabel(?string $code): ?string
    {
        return $code !== null ? (self::POSITIONS[$code] ?? null) : null;
    }

    /**
     * Foydalanuvchining mahalla tizimidagi roli (markaziy user_system_access'dan).
     */
    public function roleFor(User $user): ?string
    {
        return DB::connection('auth')->table('user_system_access as usa')
            ->join('systems as s', 's.id', '=', 'usa.system_id')
            ->where('usa.user_id', $user->id)
            ->where('usa.is_active', true)
            ->where('s.code', self::SYSTEM_CODE)
            ->value('usa.role');
    }

    public function can(User $user, string $permission): bool
    {
        $perms = $this->permissionsFor($user);

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    /**
     * Foydalanuvchining mahalla ruxsatlari ro'yxati (rol bo'yicha).
     *
     * @return array<int, string>
     */
    public function permissionsFor(User $user): array
    {
        $role = $this->roleFor($user);

        return $role === null ? [] : (self::PERMISSIONS[$role] ?? []);
    }

    /**
     * Geo ko'lam (RBAC filtr uchun). Bir xil ko'cha-scope: har bir operatsion
     * (non-admin, non-viloyat) user FAQAT o'ziga biriktirilgan ko'chalar
     * honadonlarini ko'radi. Admin va viloyat — hammasini (canSeeAll); ular
     * orasidagi farq BOSHQARUV huquqida (isAdmin), KO'RISH doirasida emas.
     * District/mahalla faqat kontekst uchun saqlanadi, filtrlashni ko'chalar
     * (streetIds) boshqaradi.
     */
    public function scopeFor(User $user): MahallaScope
    {
        $role = $this->roleFor($user);

        if ($role === 'admin') {
            return new MahallaScope(true, null, null, [], false, true);
        }

        if ($role === 'viloyat') {
            return new MahallaScope(false, null, null, [], false, true);
        }

        $profile = MahallaProfile::find($user->id);
        $streetIds = $profile !== null
            ? $profile->streetAssignments()->pluck('street_id')->all()
            : [];

        /*
         * Rais ko'chalar bilan CHEKLANMAYDI — butun mahallasini ko'radi.
         *
         * `restrictToStreets = false` bo'lgani uchun `mahallaId` endi
         * haqiqiy filtr bo'ladi (kontekst emas). Profilida mahalla
         * ko'rsatilmagan rais hech narsa ko'rmaydi — bu to'g'ri: qamrovi
         * noma'lum foydalanuvchiga butun tumanni ochish xavfli.
         */
        if ($role === 'rais') {
            return new MahallaScope(
                false,
                $profile?->district_id,
                $profile?->mahalla_id,
                $streetIds,
                false,
                false,
            );
        }

        return new MahallaScope(
            false,
            $profile?->district_id,
            $profile?->mahalla_id,
            $streetIds,
            true,
            false,
        );
    }
}
