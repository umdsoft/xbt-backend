<?php

declare(strict_types=1);

namespace App\Domains\Hr\Actions\Appeals;

use App\Domains\Hr\Models\CitizenAppeal;
use App\Domains\Hr\Services\Appeals\AppealRoutingService;
use App\Domains\Hr\Services\Appeals\AppealStatusService;
use Illuminate\Support\Facades\DB;

/**
 * Murojaat yaratish va dastlabki yo'naltirish:
 *  1. Appeal yaratiladi (status=submitted)
 *  2. AI triage queue (Phase 2) — hozir oddiy: agar category bor bo'lsa darhol routing
 *  3. Routing service ishga tushadi
 *  4. Status — routed
 *
 * Phase 2'da AI klassifikatsiya qo'shiladi (queue orqali).
 */
class CreateAppealAction
{
    public function __construct(
        private AppealRoutingService $router,
        private AppealStatusService $statusService,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, ?string $createdBy = null): CitizenAppeal
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $appeal = CitizenAppeal::create([
                ...$data,
                'status' => 'submitted',
                'created_by' => $createdBy ?? auth()->id(),
            ]);

            // Agar kategoriya allaqachon tanlangan bo'lsa — darhol routing
            if ($appeal->category_id) {
                $this->statusService->transition($appeal, 'triaged', 'Manual triage');
                $this->router->route($appeal, $appeal->created_by);
            }

            // TODO Phase 2: dispatch ClassifyAppealJob (AI triage)

            return $appeal->refresh();
        });
    }
}
