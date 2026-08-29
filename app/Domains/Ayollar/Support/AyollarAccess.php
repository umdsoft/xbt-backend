<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Support;

use App\Domains\Ayollar\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AYOLLAR BALANSI domeni RBAC.
 *
 * Rol markaziy identifikatsiyadan (`auth.user_system_access.role`), ruxsatlar
 * esa quyidagi kod xaritasidan — yoshlar/qurilish/advisor naqshi.
 *
 * NEGA spatie EMAS: platformadagi 7 domendan 6 tasi shu usulni ishlatadi
 * (spatie faqat HR'da, eski KBT merosi). Ruxsat kodda turgani uchun u
 * `git log` da ko'rinadi, prodda seeder unutilishi bilan buzilmaydi va
 * `permission:cache-reset` talab qilmaydi.
 *
 * ---------------------------------------------------------------------
 * ETTI ROL, SAKKIZ IMZO
 *
 * Promt §5 da «district_org (7 ta)» deyilgan. Ular 7 ta ALOHIDA rol EMAS,
 * BITTA `district_org` roli — qaysi idora ekani `ayollar.staff.org_code` da.
 * Sabab: idoraning vakolati ROLDAN emas, IDORADAN kelib chiqadi. Yangi
 * tasdiqlovchi organ qo'shish shu tarzda kod emas, MA'LUMOT masalasiga
 * aylanadi (aynan yoshlar modulidagi sektor naqshi).
 * ---------------------------------------------------------------------
 *
 * VAKOLATLAR BO'LINISHI: `mfy_activist` da `pii.reveal` ham, `red.names` ham
 * YO'Q. Anketani to'ldirgan odam qizil ro'yxatdagi ismlarni ko'rmaydi —
 * promt §6.3 talabi. Faol ma'lumotni YIG'ADI, u bilan ISHLAMAYDI.
 */
class AyollarAccess
{
    public const SYSTEM_CODE = 'ayollar';

    public const ROLE_ACTIVIST = 'mfy_activist';

    public const ROLE_CHAIRMAN = 'mfy_chairman';

    public const ROLE_HOKIM_ASSISTANT = 'hokim_assistant';

    public const ROLE_FAMILY_DEPT = 'district_family_dept';

    public const ROLE_DISTRICT_ORG = 'district_org';

    public const ROLE_ANALYST = 'region_analyst';

    public const ROLE_ADMIN = 'admin';

    /** @var array<int, string> */
    public const ROLES = [
        self::ROLE_ACTIVIST,
        self::ROLE_CHAIRMAN,
        self::ROLE_HOKIM_ASSISTANT,
        self::ROLE_FAMILY_DEPT,
        self::ROLE_DISTRICT_ORG,
        self::ROLE_ANALYST,
        self::ROLE_ADMIN,
    ];

    /** @var array<string, string> */
    public const ROLE_NAMES = [
        self::ROLE_ACTIVIST => 'MFY xotin-qizlar faoli',
        self::ROLE_CHAIRMAN => 'MFY raisi',
        self::ROLE_HOKIM_ASSISTANT => 'Hokim yordamchisi',
        self::ROLE_FAMILY_DEPT => 'Tuman oila va xotin-qizlar bo‘limi',
        self::ROLE_DISTRICT_ORG => 'Tuman idorasi',
        self::ROLE_ANALYST => 'Viloyat tahlilchisi',
        self::ROLE_ADMIN => 'Administrator',
    ];

    /** Doira darajasi: rol qaysi kengliкda ko'radi. */
    public const SCOPE_MAHALLA = 'mahalla';

    public const SCOPE_DISTRICT = 'district';

    public const SCOPE_REGION = 'region';

    /** @var array<string, string> */
    public const ROLE_SCOPE = [
        self::ROLE_ACTIVIST => self::SCOPE_MAHALLA,
        self::ROLE_CHAIRMAN => self::SCOPE_MAHALLA,
        self::ROLE_HOKIM_ASSISTANT => self::SCOPE_MAHALLA,
        self::ROLE_FAMILY_DEPT => self::SCOPE_DISTRICT,
        self::ROLE_DISTRICT_ORG => self::SCOPE_DISTRICT,
        self::ROLE_ANALYST => self::SCOPE_REGION,
        self::ROLE_ADMIN => self::SCOPE_REGION,
    ];

    /**
     * Qizil toifadagi ISMLARNI ko'ra oladigan rollar — FAQAT UCHTA.
     *
     * Promt §6.3. Ro'yxat alohida konstanta: u xavfsizlik qarori, oddiy
     * ruxsat emas, va kod ko'rigida bir joyda ko'rinib turishi kerak.
     */
    public const RED_NAMES_ROLES = [
        self::ROLE_CHAIRMAN,
        self::ROLE_HOKIM_ASSISTANT,
        self::ROLE_FAMILY_DEPT,
    ];

    /** @var array<string, array<int, string>> */
    private const PERMISSIONS = [
        self::ROLE_ACTIVIST => [
            'ayollar.view',
            'ayollar.household.manage',
            'ayollar.anketa.create',
            'ayollar.anketa.update',
            'ayollar.sync',
            // PII va qizil ismlar ATAYLAB yo'q — faol yig'adi, ko'rmaydi.
        ],
        self::ROLE_CHAIRMAN => [
            'ayollar.view',
            'ayollar.pii.reveal',
            'ayollar.red.names',
            'ayollar.balance.sign',
            'ayollar.export',
        ],
        self::ROLE_HOKIM_ASSISTANT => [
            'ayollar.view',
            'ayollar.pii.reveal',
            'ayollar.red.names',
            'ayollar.balance.sign',
            'ayollar.export',
        ],
        self::ROLE_FAMILY_DEPT => [
            'ayollar.view',
            'ayollar.pii.reveal',
            'ayollar.red.names',
            'ayollar.balance.sign',
            'ayollar.balance.return',
            'ayollar.workplan.view',
            'ayollar.workplan.manage',
            'ayollar.analytics.view',
            'ayollar.activists.view',
            'ayollar.export',
        ],
        self::ROLE_DISTRICT_ORG => [
            'ayollar.view',
            // Faqat O'Z qatorlarini tasdiqlaydi — qaysi qator ekani
            // `metric_registry.owner_org_code` da, servisda tekshiriladi.
            'ayollar.balance.sign',
            'ayollar.export',
        ],
        self::ROLE_ANALYST => [
            'ayollar.view',
            'ayollar.analytics.view',
            'ayollar.activists.view',
            'ayollar.workplan.view',
            'ayollar.export',
            // Viloyat tahlilchisi AGREGAT ko'radi, shaxsni emas.
        ],
        self::ROLE_ADMIN => [
            'ayollar.view',
            'ayollar.admin',
            'ayollar.user.manage',
            'ayollar.metric.manage',
            'ayollar.audit.view',
            'ayollar.analytics.view',
            'ayollar.activists.view',
            'ayollar.export',
            // Administratorda ham `pii.reveal`/`red.names` YO'Q: tizimni
            // boshqarish shaxsiy ma'lumotni ko'rish huquqini bermaydi.
        ],
    ];

    /**
     * Rol so'rov davomida keshlanadi.
     *
     * @var array<string, ?string>
     */
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

    public function isAyollar(User $user): bool
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
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();
        }

        return $this->staffCache[$user->id];
    }

    /** `mahalla` | `district` | `region` */
    public function scopeLevel(User $user): ?string
    {
        $role = $this->roleFor($user);

        return $role === null ? null : (self::ROLE_SCOPE[$role] ?? null);
    }

    public function seesEverything(User $user): bool
    {
        return $this->scopeLevel($user) === self::SCOPE_REGION;
    }

    /**
     * Qizil toifadagi ismlarni ko'ra oladimi.
     *
     * Ruxsat VA rol — ikkalasi ham tekshiriladi. Ortiqcha ko'rinishi
     * mumkin, lekin bu qoida shunchalik muhimki, uni tasodifan yangi rolga
     * ruxsat qo'shish orqali buzib bo'lmasligi kerak.
     */
    public function canSeeRedNames(User $user): bool
    {
        return in_array($this->roleFor($user), self::RED_NAMES_ROLES, true)
            && $this->can($user, 'ayollar.red.names');
    }

    /**
     * Foydalanuvchi qaysi tuman idorasi nomidan imzo qo'yadi.
     *
     * `district_family_dept` roli har doim `family_dept` idorasi — u
     * `staff.org_code` ga bog'liq emas, chunki bu rolning o'zi idorani
     * bildiradi.
     */
    public function signingOrgCode(User $user): ?string
    {
        return match ($this->roleFor($user)) {
            self::ROLE_FAMILY_DEPT => 'family_dept',
            self::ROLE_DISTRICT_ORG => $this->staffFor($user)?->org_code,
            self::ROLE_CHAIRMAN => 'rais',
            self::ROLE_HOKIM_ASSISTANT => 'hokim_yordamchisi',
            default => null,
        };
    }
}
