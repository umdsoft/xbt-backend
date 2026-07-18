<?php

declare(strict_types=1);

namespace App\Domains\Hr\Actions\Appeals;

use App\Domains\Hr\Models\CitizenAppeal;
use App\Domains\Hr\Models\CouncilDecision;
use App\Domains\Hr\Services\Appeals\AppealStatusService;
use Illuminate\Support\Facades\DB;

class RecordCouncilDecisionAction
{
    public function __construct(private AppealStatusService $statusService) {}

    /** @param array<string, mixed> $data */
    public function execute(CitizenAppeal $appeal, array $data): CouncilDecision
    {
        return DB::transaction(function () use ($appeal, $data) {
            $decision = CouncilDecision::create([
                ...$data,
                'appeal_id' => $appeal->id,
                'decided_by' => auth()->id(),
                'decided_at' => $data['decided_at'] ?? now(),
            ]);

            // Decision turi'ga qarab status'ni yangilash
            $newStatus = match ($decision->decision_type) {
                'approve', 'reject', 'partial', 'info' => 'decided',
                'escalate' => 'in_review', // qayta yo'naltirish kutilmoqda
                default => $appeal->status,
            };

            $this->statusService->transition($appeal, $newStatus, "Council decision: {$decision->decision_type}");

            // Agar yakunlovchi qaror bo'lsa — completed
            if (in_array($decision->decision_type, ['approve', 'reject', 'partial', 'info'], true)) {
                $this->statusService->transition($appeal->refresh(), 'completed', 'Final decision');
            }

            return $decision;
        });
    }
}
