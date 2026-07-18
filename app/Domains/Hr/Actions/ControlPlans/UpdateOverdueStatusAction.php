<?php

declare(strict_types=1);

namespace App\Domains\Hr\Actions\ControlPlans;

use App\Domains\Hr\Enums\ExecutionStatus;
use App\Domains\Hr\Models\ControlPlan;
use Carbon\Carbon;

/**
 * Muddati o'tgan bandlarni avtomatik 'overdue' holatiga o'tkazish.
 */
class UpdateOverdueStatusAction
{
    public function execute(ControlPlan $plan): void
    {
        $today = now()->toDateString();

        foreach ($plan->items as $item) {
            if (! $item->deadline) {
                continue;
            }

            $deadlineStr = $item->deadline instanceof Carbon
                ? $item->deadline->toDateString()
                : (string) $item->deadline;

            if ($deadlineStr <= $today
                && in_array($item->execution_status, ExecutionStatus::pendingStatuses(), true)) {
                $item->update(['execution_status' => ExecutionStatus::OVERDUE->value]);
            }
        }
    }
}
