<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Models\Document;
use App\Domains\Yoshlar\Models\EmploymentCase;
use App\Domains\Yoshlar\Models\Notification;
use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Sector;
use App\Domains\Yoshlar\Models\Task;
use App\Domains\Yoshlar\Models\TaskUpdate;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Models\YouthCase;
use App\Domains\Yoshlar\Services\EmploymentService;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Domains\Yoshlar\Support\YoshlarScope;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/api/yoshlar/context` — SPA ishga tushganda BIR marta chaqiriladi:
 * foydalanuvchi, roli, ruxsatlari, ko'rish doirasi va barcha spravochniklar.
 *
 * Ma'lumot ikkala alifboda (`name_lat` + `name_cyr`) beriladi: til rejimini
 * SPA tanlaydi, API javobi undan mustaqil (keshlash osonroq).
 */
class ContextController extends Controller
{
    public function __invoke(Request $request, YoshlarAccess $access, YoshlarScope $scope): JsonResponse
    {
        $user = $request->user();
        $staff = $access->staffFor($user);
        $org = $staff?->organization;

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'login' => $user->login],
            'role' => $access->roleFor($user),
            'role_name' => YoshlarAccess::ROLE_NAMES[$access->roleFor($user)] ?? null,
            'permissions' => $access->permissionsFor($user),
            'sees_everything' => $access->seesEverything($user),
            'viewer_only' => $access->isViewerOnly($user),
            'scope' => [
                'org_id' => $staff?->org_id,
                'org_name' => $org?->name_lat,
                'org_type' => $org?->type,
                'district_id' => $org?->district_id,
                'sector_id' => $org?->sector_id,
                'can_patronage' => (bool) $staff?->can_patronage,
            ],
            // Navigatsiya nishonlari — SPA har sahifada qayta so'ramasin.
            'badges' => [
                'pending_youth' => $scope->applyYouth(Youth::query(), $user)
                    ->where('verification_status', 'pending')->count(),
                // Tasdiqlash navbati — foydalanuvchi qaysi bosqichda ishlasa, o'sha.
                'task_queue' => $this->taskQueueCount($user, $access, $scope),
                // Muddati o'tgan topshiriqlar — menyuda qizil nishon.
                'tasks_overdue' => $scope->applyTask(Task::query(), $user)->overdue()->count(),
                'employment_queue' => $this->employmentQueueCount($user, $access, $scope),
                // O'qilmagan bildirishnomalar — qo'ng'iroq nishoni (TZ 5.8).
                'notifications' => Notification::query()->where('user_id', $user->id)->unread()->count(),
            ],
            'reference' => [
                'districts' => DB::connection('master')->table('districts')
                    ->orderBy('sort_order')->get(['id', 'name_lat', 'name_cyr', 'soato_code'])->all(),
                'mahallas' => DB::connection('master')->table('mahallas')
                    ->where('is_active', true)->orderBy('sort_order')
                    ->get(['id', 'district_id', 'name_lat', 'name_cyr'])->all(),
                'sectors' => Sector::query()->where('is_active', true)
                    ->orderBy('sort_order')->get(['id', 'code', 'name_lat', 'name_cyr'])->all(),
                'organizations' => Organization::query()->where('is_active', true)
                    ->orderBy('name_lat')
                    ->get(['id', 'type', 'parent_id', 'district_id', 'sector_id', 'name_lat', 'name_cyr'])->all(),
                'education_statuses' => Youth::EDUCATION_STATUSES,
                'employment_statuses' => Youth::EMPLOYMENT_STATUSES,
                'registry_statuses' => Youth::REGISTRY_STATUSES,
                'organization_types' => Organization::TYPES,
                'roles' => YoshlarAccess::ROLE_NAMES,
                'task_statuses' => Task::STATUSES,
                'task_priorities' => Task::PRIORITIES,
                'employment_statuses_chain' => EmploymentCase::STATUSES,
                'document_categories' => Document::CATEGORIES,
                'case_categories' => YouthCase::CATEGORIES,
                'case_statuses' => YouthCase::STATUSES,
            ],
        ]);
    }

    /**
     * Foydalanuvchi tasdiqlashi kutilayotgan hisobotlar soni.
     *
     * Bosqich roldan kelib chiqadi: sektor boshqarmasi `sector_review` ni,
     * yoshlar boshqarmasi `youth_review` ni ko'radi. Ikkalasi ham bo'lmasa 0.
     */
    private function taskQueueCount(User $user, YoshlarAccess $access, YoshlarScope $scope): int
    {
        $stage = match (true) {
            $access->can($user, 'yoshlar.task.review.sector') => 'sector_review',
            $access->can($user, 'yoshlar.task.review.youth') => 'youth_review',
            default => null,
        };

        if ($stage === null) {
            return 0;
        }

        return TaskUpdate::query()
            ->where('review_stage', $stage)
            ->whereIn('task_id', $scope->applyTask(Task::query()->select('id'), $user))
            ->count();
    }

    /**
     * Soliq tasdig'i kutilayotgan bandlik arizalari.
     *
     * Faqat SOLIQ sektori xodimida ko'rinadi: bandlik bo'limi o'z arizasini
     * o'zi tasdiqlamaydi, shuning uchun unda navbat ham bo'lmaydi.
     */
    private function employmentQueueCount(User $user, YoshlarAccess $access, YoshlarScope $scope): int
    {
        if ($access->sectorCodeFor($user) !== EmploymentService::TAX_SECTOR) {
            return 0;
        }

        $status = match (true) {
            $access->can($user, 'yoshlar.employment.review.district') => EmploymentCase::STATUS_SUBMITTED,
            $access->can($user, 'yoshlar.employment.review.province') => EmploymentCase::STATUS_TAX_DISTRICT,
            default => null,
        };

        if ($status === null) {
            return 0;
        }

        $districts = $scope->districtIds($user);
        $query = EmploymentCase::query()->where('status', $status);

        if ($districts === []) {
            return 0;
        }

        if ($districts !== null) {
            $query->whereIn('district_id', $districts);
        }

        return $query->count();
    }
}
