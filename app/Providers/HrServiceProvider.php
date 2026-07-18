<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Hr\Models\CitizenAppeal;
use App\Domains\Hr\Models\ControlPlan;
use App\Domains\Hr\Models\Employee;
use App\Domains\Hr\Models\HokimYordamchisi;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Models\MahallaCouncil;
use App\Domains\Hr\Models\Organization;
use App\Domains\Hr\Models\YoshlarYetakchisi;
use App\Domains\Hr\Models\YouthMeeting;
use App\Domains\Hr\Policies\CitizenAppealPolicy;
use App\Domains\Hr\Policies\ControlPlanPolicy;
use App\Domains\Hr\Policies\EmployeePolicy;
use App\Domains\Hr\Policies\HokimYordamchisiPolicy;
use App\Domains\Hr\Policies\MahallaCouncilPolicy;
use App\Domains\Hr\Policies\OrganizationPolicy;
use App\Domains\Hr\Policies\UserPolicy;
use App\Domains\Hr\Policies\YoshlarYetakchisiPolicy;
use App\Domains\Hr\Policies\YouthMeetingPolicy;
use App\Domains\Hr\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Domains\Hr\Repositories\Eloquent\EloquentEmployeeRepository;
use App\Domains\Hr\Support\HrAccess;
use App\Domains\Hr\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * HR (KBT) domeni servis provayderi — tenant konteksti, RBAC (spatie) va
 * policy'larni ro'yxatga oladi. Markaziy identifikatsiya + mahalla domeni
 * o'zgarishsiz qoladi.
 */
class HrServiceProvider extends ServiceProvider
{
    /**
     * Repository interface → Eloquent implementation.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        EmployeeRepositoryInterface::class => EloquentEmployeeRepository::class,
    ];

    public function register(): void
    {
        // Tenant konteksti + HR kirish helperi — request davomida bitta instance.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(HrAccess::class);
    }

    public function boot(): void
    {
        // Super-admin — barcha HR ruxsatlariga avtomatik ruxsat (faqat HR aktyori
        // uchun; markaziy User yoki boshqa domenlarga ta'sir qilmaydi).
        Gate::before(function ($user, $ability) {
            if ($user instanceof HrProfile && $user->hasRole('super-admin')) {
                return true;
            }

            return null;
        });

        foreach ($this->policies() as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    /**
     * @return array<class-string, class-string>
     */
    private function policies(): array
    {
        return [
            Employee::class => EmployeePolicy::class,
            HrProfile::class => UserPolicy::class,
            ControlPlan::class => ControlPlanPolicy::class,
            Organization::class => OrganizationPolicy::class,
            CitizenAppeal::class => CitizenAppealPolicy::class,
            YouthMeeting::class => YouthMeetingPolicy::class,
            MahallaCouncil::class => MahallaCouncilPolicy::class,
            HokimYordamchisi::class => HokimYordamchisiPolicy::class,
            YoshlarYetakchisi::class => YoshlarYetakchisiPolicy::class,
        ];
    }
}
