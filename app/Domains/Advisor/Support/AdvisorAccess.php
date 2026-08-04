<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Support;

use App\Domains\Advisor\Models\Advisor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * advisor domeni RBAC — markaziy identifikatsiyadan rol/ruxsat hal qiladi.
 * Rol = user_system_access.role ('advisor' tizimi); ruxsat = kod xaritasi
 * (mahalla MahallaAccess naqshi).
 *
 * 3 daraja (spec §2):
 *   advisor_viloyat — viloyat maslahatchisi (super-nazoratchi: barcha tuman + arxiv
 *                     + KPI + reyting; topshiriq beradi; tasdiq/qaytarish; eksport).
 *   advisor_bolinma — bo'linma mutaxassisi (hisobot QA, umumlashtirish, KPI yig'ish).
 *   advisor_tuman   — tuman/shahar maslahatchisi (o'z topshiriq/loyiha/KPI/reyting).
 */
class AdvisorAccess
{
    public const SYSTEM_CODE = 'advisor';

    /**
     * Barcha rollar (SSO / seeder / picker uchun).
     *
     * @var array<int, string>
     */
    public const ROLES = ['advisor_viloyat', 'advisor_bolinma', 'advisor_tuman'];

    /**
     * Rol => ruxsatlar. `*` = hammasi (viloyat super-nazoratchi).
     *
     * @var array<string, array<int, string>>
     */
    private const PERMISSIONS = [
        // Viloyat maslahatchisi — super-nazoratchi (hamma narsani ko'radi/boshqaradi).
        // `*` quyidagilarni ham qamraydi: kpi.view/enter/approve, rankings.view/compute
        // (KPI tasdiqi va reyting hisobi FAQAT viloyat huquqi — spec §7, §8) hamda
        // chora-tadbirlar: plan.view/plan.progress/plan.manage (istalgan tuman bajarilishini tahrir).
        'advisor_viloyat' => ['*'],
        // Bo'linma mutaxassisi — QA + qaytarish + tahlil + KPI yig'ish + arxiv eksport
        // + faoliyat nazorati (oversight.view: barcha maslahatchilar kesimi — spec §9).
        // (topshiriq bermaydi, TASDIQLAMAYDI — bu viloyat huquqi).
        'advisor_bolinma' => [
            'dashboard.view', 'tasks.view', 'reports.view', 'reports.qa', 'reports.return',
            'archive.view', 'archive.export',
            'kpi.view', 'kpi.enter', 'projects.view', 'rankings.view',
            'activity.view', 'oversight.view',
            'plan.view',
            'monitoring.view',
        ],
        // Tuman/shahar maslahatchisi — o'z ishini yuritadi (o'z tumani kesimida).
        // KPI: o'z tumani qiymatini kiritadi (kpi.enter); tasdiq/derivatsiya YO'Q
        // (viloyat huquqi). Reyting: faqat ko'radi (rankings.view). Faoliyat:
        // FAQAT o'zini (activity.view; oversight YO'Q — u viloyat/bo'linma huquqi).
        'advisor_tuman' => [
            'dashboard.view', 'tasks.view', 'reports.view', 'reports.submit',
            'archive.view',
            'kpi.view', 'kpi.enter', 'projects.view', 'projects.manage', 'rankings.view',
            'activity.view',
            'plan.view', 'plan.progress',
            'monitoring.view', 'monitoring.enter',
        ],
    ];

    /**
     * Per-so'rov memo (roleFor 2-3×, advisorFor 2× chaqiriladi). AdvisorAccess
     * singleton sifatida bog'langan — so'rov davomida memo baham ko'riladi.
     * User id bo'yicha kalitlanadi (null ham keshlanadi — array_key_exists bilan).
     *
     * @var array<string, ?string>
     */
    private array $roleCache = [];

    /** @var array<string, ?Advisor> */
    private array $advisorCache = [];

    /**
     * Foydalanuvchining advisor tizimidagi roli (markaziy user_system_access'dan).
     */
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

    /**
     * Foydalanuvchi advisor tizimiga (biror rol bilan) kira oladimi?
     */
    public function isAdvisor(User $user): bool
    {
        return $this->roleFor($user) !== null;
    }

    public function can(User $user, string $permission): bool
    {
        $perms = $this->permissionsFor($user);

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    /**
     * Foydalanuvchining advisor ruxsatlari ro'yxati (rol bo'yicha).
     *
     * @return array<int, string>
     */
    public function permissionsFor(User $user): array
    {
        $role = $this->roleFor($user);

        return $role === null ? [] : (self::PERMISSIONS[$role] ?? []);
    }

    /**
     * Foydalanuvchining advisor profili (advisor.advisors) — yoki null.
     */
    public function advisorFor(User $user): ?Advisor
    {
        if (! array_key_exists($user->id, $this->advisorCache)) {
            $this->advisorCache[$user->id] = Advisor::query()->where('user_id', $user->id)->first();
        }

        return $this->advisorCache[$user->id];
    }

    /**
     * Ko'rish qamrovi — rol + tuman (topshiriq/arxiv filtrlashda IDOR himoyasi).
     */
    public function scopeFor(User $user): AdvisorScope
    {
        $advisor = $this->advisorFor($user);

        return new AdvisorScope(
            $this->roleFor($user),
            $advisor?->district_id,
            $advisor?->id,
        );
    }
}
