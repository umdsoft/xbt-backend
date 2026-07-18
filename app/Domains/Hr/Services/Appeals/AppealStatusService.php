<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services\Appeals;

use App\Domains\Hr\Models\AppealStatusHistory;
use App\Domains\Hr\Models\CitizenAppeal;

/**
 * Appeal status'ni o'zgartirish va status_history'ga yozish.
 * Avtomatik first_response_at va completed_at to'ldirish.
 */
class AppealStatusService
{
    public function transition(CitizenAppeal $appeal, string $toStatus, ?string $reason = null): CitizenAppeal
    {
        $fromStatus = $appeal->status;
        if ($fromStatus === $toStatus) {
            return $appeal;
        }

        $userId = auth()->id();

        AppealStatusHistory::create([
            'appeal_id' => $appeal->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by' => $userId,
            'reason' => $reason,
            'changed_at' => now(),
        ]);

        $updates = ['status' => $toStatus];

        // first_response_at — birinchi marta operator tegtigan paytda
        if (! $appeal->first_response_at && in_array($toStatus, ['triaged', 'routed', 'in_review'], true)) {
            $updates['first_response_at'] = now();
        }

        // completed_at
        if ($toStatus === 'completed') {
            $updates['completed_at'] = now();
        }

        $appeal->update($updates);

        return $appeal->refresh();
    }
}
