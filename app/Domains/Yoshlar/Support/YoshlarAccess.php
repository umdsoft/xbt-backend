<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Support;

use App\Domains\Yoshlar\Models\Sector;
use App\Domains\Yoshlar\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * YOSHLAR domeni RBAC — rol markaziy identifikatsiyadan, ruxsat kod xaritasidan
 * (qurilish/advisor naqshi).
 *
 * VAKOLATLAR BO'LINISHI: `yoshlar_admin` da `youth.verify` YO'Q. Hisob ochuvchi
 * odam ayni paytda tasdiqlay olsa, o'ziga hisob ochib, o'zi kiritib, o'zi
 * tasdiqlardi — nazorat sikli soxta bo'lardi.
 *
 * PII need-to-know: `yoshlar_hokim_orinbosari` — eng yuqori mansab, lekin PINFL
 * ko'rmaydi. Rahbariyat agregat ko'radi, shaxsni emas.
 */
class YoshlarAccess
{
    public const SYSTEM_CODE = 'yoshlar';

    /** @var array<int, string> */
    public const ROLES = [
        'yoshlar_hokim_orinbosari',
        'yoshlar_admin',
        'yoshlar_boshqarma',
        'yoshlar_bolim',
        'sektor_boshqarma',
        'sektor_bolim',
    ];

    /** @var array<string, string> */
    public const ROLE_NAMES = [
        'yoshlar_hokim_orinbosari' => 'Hokim o‘rinbosari',
        'yoshlar_admin' => 'Administrator',
        'yoshlar_boshqarma' => 'Viloyat yoshlar boshqarmasi',
        'yoshlar_bolim' => 'Tuman yoshlar bo‘limi',
        'sektor_boshqarma' => 'Viloyat sektor boshqarmasi',
        'sektor_bolim' => 'Tuman sektor bo‘limi',
    ];

    /** Yozish taqiqlangan rollar. */
    public const VIEWER_ROLES = ['yoshlar_hokim_orinbosari'];

    /** Tashkilotsiz ishlay oladigan rollar (viloyat darajasi/super). */
    public const ORG_OPTIONAL_ROLES = ['yoshlar_hokim_orinbosari', 'yoshlar_admin'];

    /** @var array<string, array<int, string>> */
    private const PERMISSIONS = [
        'yoshlar_admin' => [
            'yoshlar.view',
            'yoshlar.export',
            'yoshlar.youth.create',
            'yoshlar.youth.update',
            'yoshlar.youth.delete',
            'yoshlar.pii.reveal',
            'yoshlar.org.manage',
            'yoshlar.staff.manage',
            'yoshlar.user.manage',
            'yoshlar.audit.view',
            'yoshlar.task.view',
            'yoshlar.task.manage',
            'yoshlar.employment.view',
            'yoshlar.case.view',
            'yoshlar.case.manage',
            'yoshlar.patronage.view',
        ],
        'yoshlar_hokim_orinbosari' => [
            'yoshlar.view', 'yoshlar.export', 'yoshlar.task.view', 'yoshlar.employment.view',
            'yoshlar.case.view', 'yoshlar.patronage.view',
        ],
        'yoshlar_boshqarma' => [
            'yoshlar.view',
            'yoshlar.export',
            'yoshlar.youth.verify',
            'yoshlar.pii.reveal',
            'yoshlar.audit.view',
            'yoshlar.task.view',
            'yoshlar.task.review.youth',
            'yoshlar.employment.view',
            'yoshlar.case.view',
            'yoshlar.patronage.view',
        ],
        'yoshlar_bolim' => [
            'yoshlar.view',
            'yoshlar.export',
            'yoshlar.youth.create',
            'yoshlar.youth.update',
            'yoshlar.youth.verify',
            'yoshlar.pii.reveal',
            // Tuman yoshlar bo'limi topshiriq zanjirida QATNASHMAYDI (TZ 8),
            // lekin o'z tumanidagi ijro holatini ko'radi — nazorat uchun.
            'yoshlar.task.view',
            'yoshlar.employment.view',
            'yoshlar.case.view',
            'yoshlar.case.manage',
            'yoshlar.patronage.view',
        ],
        'sektor_boshqarma' => [
            'yoshlar.view',
            'yoshlar.export',
            'yoshlar.task.view',
            'yoshlar.task.execute',
            'yoshlar.task.review.sector',
            'yoshlar.employment.view',
            // Soliq tasdigʻi: RUXSAT rolda, lekin SEKTOR tekshiruvi servisda
            // (faqat sector=soliq tashkiloti tasdiqlay oladi).
            'yoshlar.employment.review.province',
            'yoshlar.case.view',
            'yoshlar.patronage.view',
        ],
        'sektor_bolim' => [
            'yoshlar.view',
            'yoshlar.youth.create',
            'yoshlar.task.view',
            'yoshlar.task.execute',
            'yoshlar.employment.view',
            'yoshlar.employment.create',
            'yoshlar.employment.review.district',
            'yoshlar.case.view',
            'yoshlar.case.manage',
            'yoshlar.patronage.view',
            'yoshlar.patronage.manage',
        ],
    ];

    /** @var array<string, ?string> */
    private array $roleCache = [];

    /** @var array<string, ?Staff> */
    private array $staffCache = [];

    public function roleFor(User $user): ?string
    {
        if (! array_key_exists($user->id, $this->roleCache)) {
            $this->roleCache[$user->id] = DB::connection('auth')->table('user_system_access as usa')
                ->join('systems as s', 's.id', '=', 'usa.system_id')
                ->where('usa.user_id', $user->id)
                ->where('usa.is_active', true)
                ->where('s.code', self::SYSTEM_CODE)
                ->value('usa.role');
        }

        return $this->roleCache[$user->id];
    }

    public function isYoshlar(User $user): bool
    {
        return $this->roleFor($user) !== null;
    }

    public function can(User $user, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($user), true);
    }

    /** @return array<int, string> */
    public function permissionsFor(User $user): array
    {
        $role = $this->roleFor($user);

        return $role === null ? [] : (self::PERMISSIONS[$role] ?? []);
    }

    public function staffFor(User $user): ?Staff
    {
        if (! array_key_exists($user->id, $this->staffCache)) {
            $this->staffCache[$user->id] = Staff::query()
                ->with('organization')
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();
        }

        return $this->staffCache[$user->id];
    }

    /**
     * Foydalanuvchi tashkilotining sektor KODI (`bandlik`, `soliq`, ...).
     *
     * NEGA KERAK: F3 bandlik zanjiri rolga emas, rol + SEKTOR juftligiga
     * bog'lanadi — `sektor_bolim` roli ham bandlik, ham soliq bo'limida
     * bo'lishi mumkin, lekin soliq tasdig'ini faqat soliqchi bera oladi.
     * Shu tufayli yangi tasdiqlovchi organ qo'shish kod emas, ma'lumot
     * masalasiga aylanadi.
     */
    public function sectorCodeFor(User $user): ?string
    {
        $sectorId = $this->staffFor($user)?->organization?->sector_id;

        if ($sectorId === null) {
            return null;
        }

        return Sector::query()->whereKey($sectorId)->value('code');
    }

    /** Viloyat darajasi — reyestrni to'liq ko'radi (geo scope qo'llanmaydi). */
    public function seesEverything(User $user): bool
    {
        return in_array($this->roleFor($user), [
            'yoshlar_admin',
            'yoshlar_hokim_orinbosari',
            'yoshlar_boshqarma',
            'sektor_boshqarma',
        ], true);
    }

    public function isViewerOnly(User $user): bool
    {
        return in_array($this->roleFor($user), self::VIEWER_ROLES, true);
    }
}
