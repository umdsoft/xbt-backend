<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Support;

use App\Domains\Qurilish\Models\QurilishProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * QURILISH domeni RBAC — markaziy identifikatsiyadan rol/ruxsat (advisor naqshi).
 * Rol = user_system_access.role ('qurilish' tizimi); ruxsat = kod xaritasi.
 *
 * Rollar:
 *   qurilish_hokimlik    — viloyat hokimligi: BARCHA loyihani ko'radi, yozmaydi.
 *   qurilish_prokuratura — viloyat prokuraturasi: xuddi shunday, faqat ko'rish.
 *   qurilish_buyurtmachi — o'ziga biriktirilgan loyihalarni yuritadi.
 *   qurilish_boshqarma   — o'z obyektlarini kiritadi + ta'mirtalab reyestr.
 *   qurilish_admin       — hisob/spravochnik/import boshqaruvi (super).
 */
class QurilishAccess
{
    public const SYSTEM_CODE = 'qurilish';

    /** @var array<int, string> */
    public const ROLES = [
        'qurilish_hokimlik',
        'qurilish_prokuratura',
        'qurilish_buyurtmachi',
        'qurilish_boshqarma',
        'qurilish_moderator',
        'qurilish_admin',
    ];

    /** Rol nomlari (kirill) — UI va buyruq chiqishi uchun yagona manba. */
    public const ROLE_NAMES = [
        'qurilish_hokimlik' => 'Вилоят ҳокимлиги',
        'qurilish_prokuratura' => 'Вилоят прокуратураси',
        'qurilish_buyurtmachi' => 'Буюртмачи',
        'qurilish_boshqarma' => 'Бошқарма',
        'qurilish_moderator' => 'Тизим модератори',
        'qurilish_admin' => 'Администратор',
    ];

    /**
     * Obyekt ma'lumotini tahrirlay olmaydigan rollar (viloyat darajasi).
     * Prokuratura shu ro'yxatda qoladi: u bosqichni MODERATSIYA qiladi,
     * lekin obyekt maydonlarini o'zgartirmaydi.
     */
    public const VIEWER_ROLES = ['qurilish_hokimlik', 'qurilish_prokuratura', 'qurilish_moderator'];

    /**
     * Tizim moderatori — hisob va spravochnik boshqaruvi.
     *
     * NEGA ADMIN'DAN AJRATILGAN: hisob ochuvchi odam ayni paytda qurilish
     * bosqichini tasdiqlay olsa, u o'ziga buyurtmachi hisobi ochib, o'zi
     * yuborib, o'zi tasdiqlay olardi. Vakolatlar bo'linishi shuni to'sadi:
     * moderatorda `qurilish.stage.moderate` ham, `object.update` ham YO'Q.
     */
    public const MODERATOR_ROLE = 'qurilish_moderator';

    /** @var array<string, array<int, string>> */
    private const PERMISSIONS = [
        'qurilish_admin' => ['*'],
        'qurilish_moderator' => [
            'qurilish.view',
            'qurilish.export',
            'qurilish.user.manage',       // login/parol ochish, faolsizlantirish
            'qurilish.reference.manage',  // dastur, soha, tashkilot CRUD
        ],
        // Hokimlik — kuzatuvchi: hech nima yozmaydi.
        'qurilish_hokimlik' => ['qurilish.view', 'qurilish.export'],
        // Prokuratura — MODERATOR (TZ v2.0, 11.1). Obyekt ma'lumotini
        // tahrirlamaydi, lekin bosqichni tasdiqlaydi yoki rad etadi:
        // bosqich prokuratura tasdig'isiz yopilmaydi.
        'qurilish_prokuratura' => ['qurilish.view', 'qurilish.export', 'qurilish.stage.moderate'],
        'qurilish_buyurtmachi' => [
            'qurilish.view',
            'qurilish.export',
            'qurilish.object.update',
            'qurilish.stage.update',
            'qurilish.document.manage',
        ],
        'qurilish_boshqarma' => [
            'qurilish.view',
            'qurilish.export',
            'qurilish.object.create',
            'qurilish.object.update',
            'qurilish.document.manage',
            'qurilish.repair.manage',
        ],
    ];

    /** @var array<string, ?string> */
    private array $roleCache = [];

    /** @var array<string, ?QurilishProfile> */
    private array $profileCache = [];

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

    public function isQurilish(User $user): bool
    {
        return $this->roleFor($user) !== null;
    }

    public function can(User $user, string $permission): bool
    {
        $perms = $this->permissionsFor($user);

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    /** @return array<int, string> */
    public function permissionsFor(User $user): array
    {
        $role = $this->roleFor($user);

        return $role === null ? [] : (self::PERMISSIONS[$role] ?? []);
    }

    public function profileFor(User $user): ?QurilishProfile
    {
        if (! array_key_exists($user->id, $this->profileCache)) {
            $this->profileCache[$user->id] = QurilishProfile::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();
        }

        return $this->profileCache[$user->id];
    }

    /**
     * Viloyat darajasi — barcha obyektni ko'radi (scope qo'llanmaydi).
     * Hokimlik/prokuratura ko'rish uchun, admin boshqaruv uchun.
     */
    public function seesEverything(User $user): bool
    {
        $role = $this->roleFor($user);

        return $role === 'qurilish_admin'
            || $role === self::MODERATOR_ROLE
            || in_array($role, self::VIEWER_ROLES, true);
    }

    /** Faqat ko'ruvchimi (yozish taqiqlangan). */
    public function isViewerOnly(User $user): bool
    {
        return in_array($this->roleFor($user), self::VIEWER_ROLES, true);
    }
}
