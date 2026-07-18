<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services\Appeals;

use App\Domains\Hr\Models\AppealAssignment;
use App\Domains\Hr\Models\CitizenAppeal;
use App\Domains\Hr\Models\MahallaCouncil;
use App\Domains\Hr\Models\RoutingRule;

/**
 * Murojaatni tegishli mas'ul (council/department/user) ga yo'naltirish.
 *
 * Mantiq:
 *  1. Tenant uchun routing_rules tekshiriladi (kategoriya bo'yicha)
 *  2. Topilmasa — kategoriyaning default_route_type'i ishlatiladi
 *  3. Default: applicant_mahalla_id'ga tegishli mahalla yettiligi
 *
 * SOLID-S: faqat yo'naltirishga mas'ul.
 */
class AppealRoutingService
{
    /**
     * Murojaatni avtomatik yo'naltirish.
     */
    public function route(CitizenAppeal $appeal, ?int $assignedBy = null): ?AppealAssignment
    {
        $assignedBy ??= auth()->id();
        if (! $assignedBy) {
            return null;
        }

        $target = $this->findTarget($appeal);
        if (! $target) {
            return null;
        }

        // Eski active assignmentlarni yopish
        AppealAssignment::query()
            ->where('appeal_id', $appeal->id)
            ->where('status', 'active')
            ->update(['status' => 'transferred']);

        // SLA hisoblash
        $slaHours = $this->calculateSlaHours($appeal, $target['rule'] ?? null);

        $assignment = AppealAssignment::create([
            'appeal_id' => $appeal->id,
            'assignee_type' => $target['type'],
            'assignee_id' => $target['id'],
            'assigned_by' => $assignedBy,
            'reason' => $target['reason'] ?? 'Avtomatik yo\'naltirish',
            'status' => 'active',
            'assigned_at' => now(),
        ]);

        // Appeal status va SLA yangilash
        $appeal->update([
            'status' => 'routed',
            'sla_due_at' => now()->addHours($slaHours),
        ]);

        return $assignment;
    }

    /**
     * Mas'ulni topish: rule → category default → mahalla council fallback.
     *
     * @return array{type: string, id: int, reason?: string, rule?: RoutingRule}|null
     */
    private function findTarget(CitizenAppeal $appeal): ?array
    {
        // 1. Tenant'ga tegishli rule (sub-kategoriya bo'yicha)
        $rule = $this->findRule($appeal->hokimlik_id, $appeal->sub_category_id ?? $appeal->category_id);
        if ($rule) {
            $assigneeId = $rule->assignee_id ?? $this->resolveDefault($appeal, $rule->assignee_type);
            if ($assigneeId) {
                return [
                    'type' => $rule->assignee_type,
                    'id' => $assigneeId,
                    'reason' => "Routing rule #{$rule->id}",
                    'rule' => $rule,
                ];
            }
        }

        // 2. Kategoriyaning default_route_type
        $category = $appeal->subCategory ?? $appeal->category;
        if ($category && $category->default_route_type) {
            $assigneeId = $this->resolveDefault($appeal, $category->default_route_type);
            if ($assigneeId) {
                return [
                    'type' => $category->default_route_type,
                    'id' => $assigneeId,
                    'reason' => "Default for category {$category->code}",
                ];
            }
        }

        // 3. Default: mahalla yettiligi
        $councilId = $this->findMahallaCouncilId($appeal);
        if ($councilId) {
            return [
                'type' => 'council',
                'id' => $councilId,
                'reason' => 'Mahalla yettiligi (default)',
            ];
        }

        return null;
    }

    private function findRule(int $hokimlikId, ?int $categoryId): ?RoutingRule
    {
        if (! $categoryId) {
            return null;
        }

        return RoutingRule::query()
            ->where('hokimlik_id', $hokimlikId)
            ->where('category_id', $categoryId)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->first();
    }

    private function resolveDefault(CitizenAppeal $appeal, string $type): ?int
    {
        return match ($type) {
            'council' => $this->findMahallaCouncilId($appeal),
            'department' => $appeal->hokimlik_id,
            default => null,
        };
    }

    private function findMahallaCouncilId(CitizenAppeal $appeal): ?int
    {
        if (! $appeal->mahalla_id) {
            return null;
        }

        return MahallaCouncil::query()
            ->where('mahalla_id', $appeal->mahalla_id)
            ->where('is_active', true)
            ->value('id');
    }

    private function calculateSlaHours(CitizenAppeal $appeal, ?RoutingRule $rule): int
    {
        if ($rule?->sla_hours_override) {
            return (int) $rule->sla_hours_override;
        }

        $category = $appeal->subCategory ?? $appeal->category;

        return (int) ($category?->default_sla_hours ?? 168);
    }
}
