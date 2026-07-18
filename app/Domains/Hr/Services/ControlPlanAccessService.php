<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services;

use App\Domains\Hr\Enums\AssigneeType;
use App\Domains\Hr\Models\ControlPlanItem;
use App\Domains\Hr\Models\HrProfile;

/**
 * Назорат режа / топшириқ учун рухсатларни марказлаштирилган тарзда тeкшириш.
 *
 * Полиморфик масъул: ички ходим (user) ёки ташкилот (organization).
 * Ташкилот фойдаланувчиси — фақат ўз ташкилотига бириктирилган топшириқларни
 * кўради ва ижро ҳисоботини юклайди (EDO).
 */
class ControlPlanAccessService
{
    public function canViewItem(?HrProfile $user, ControlPlanItem $item): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return true;
        }

        // Ташкилот фойдаланувчиси — фақат ўзига бириктирилган
        if ($user->organization_id !== null) {
            return $this->isOrgAssignee($user, $item);
        }

        // Ички ходим — фақат ЎЗ ҲОКИМЛИГИдаги банд (cross-tenant IDOR олдини олиш).
        if (! $this->sameTenant($user, $item)) {
            return false;
        }

        return $user->hasPermissionTo('tadbirlar.view')
            || $this->isResponsibleOrColleague($user, $item)
            || $this->isCreator($user, $item);
    }

    public function canEditItem(?HrProfile $user, ControlPlanItem $item): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return true;
        }

        // Ташкилот фойдаланувчиси — ижро ҳисоботини юклай олади (EDO)
        if ($user->organization_id !== null) {
            return $user->hasPermissionTo('topshiriqlar.report') && $this->isOrgAssignee($user, $item);
        }

        if (! $user->hasPermissionTo('tadbirlar.update')) {
            return false;
        }

        // Ички ходим — фақат ЎЗ ҲОКИМЛИГИдаги банд.
        if (! $this->sameTenant($user, $item)) {
            return false;
        }

        return $this->isCreator($user, $item)
            || $this->isResponsibleOrColleague($user, $item);
    }

    /**
     * Банд фойдаланувчининг ҳокимлигигами? Cross-tenant (viloyat-admin) — барчаси.
     * (super-admin юқорида аллақачон true қайтарган.)
     */
    private function sameTenant(HrProfile $user, ControlPlanItem $item): bool
    {
        if ($user->hasRole('viloyat-admin')) {
            return true;
        }

        return $item->hokimlik_id !== null && $item->hokimlik_id === $user->hokimlik_id;
    }

    /**
     * Топшириқни назоратдан ечиш — фақат яратган котибият мудири
     * ёки масъул ички ходим (ташкилот эмас).
     */
    public function canRemoveFromControl(?HrProfile $user, ControlPlanItem $item): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return true;
        }

        // Ташкилот фойдаланувчиси ечолмайди (фақат ижро юклайди)
        if ($user->organization_id !== null) {
            return false;
        }

        return $this->isCreator($user, $item) || $this->isResponsibleEmployee($user, $item);
    }

    /**
     * Ижрони тасдиқлаш/қайтариш — фақат яратган котибият мудири ёки масъул ходим.
     */
    public function canReview(?HrProfile $user, ControlPlanItem $item): bool
    {
        return $this->canRemoveFromControl($user, $item);
    }

    /** Топшириқни яратган (мустақил ёки режа орқали). */
    private function isCreator(HrProfile $user, ControlPlanItem $item): bool
    {
        return $item->created_by === $user->id
            || $item->plan?->created_by === $user->id;
    }

    /** Ушбу банднинг масъул ички ходими. */
    private function isResponsibleEmployee(HrProfile $user, ControlPlanItem $item): bool
    {
        return ($item->responsibles ?? collect())->contains(function ($r) use ($user) {
            $type = $r->assignee_type instanceof AssigneeType ? $r->assignee_type->value : $r->assignee_type;

            return ($type === 'user' && (string) $r->assignee_id === (string) $user->id)
                || (string) $r->user_id === (string) $user->id;
        });
    }

    /** Ушбу банд ушбу ташкилотга бириктирилганми. */
    private function isOrgAssignee(HrProfile $user, ControlPlanItem $item): bool
    {
        return ($item->responsibles ?? collect())->contains(function ($r) use ($user) {
            $type = $r->assignee_type instanceof AssigneeType ? $r->assignee_type->value : $r->assignee_type;

            return $type === 'organization' && (string) $r->assignee_id === (string) $user->organization_id;
        });
    }

    /**
     * Фойдаланувчи ушбу банднинг масъули ёки масъуллар билан бир бўлимда ишлайдими.
     */
    private function isResponsibleOrColleague(HrProfile $user, ControlPlanItem $item): bool
    {
        $responsibles = $item->responsibles ?? collect();

        if ($this->isResponsibleEmployee($user, $item)) {
            return true;
        }

        if (! $user->department_id) {
            return false;
        }

        $responsibleDeptIds = $responsibles
            ->whereNotNull('user_id')
            ->map(fn ($r) => $r->user?->department_id)
            ->filter()
            ->unique();

        return $responsibleDeptIds->contains($user->department_id);
    }
}
