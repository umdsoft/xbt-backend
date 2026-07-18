<?php

declare(strict_types=1);

namespace App\Domains\Hr\Actions\ControlPlans;

use App\Domains\Hr\Models\ControlPlan;
use Illuminate\Support\Facades\DB;

/**
 * Bandlar va mas'ullarni saqlash logikasi.
 * Create va Update da bir xil ishlatiladi (DRY).
 */
class SaveControlPlanItemsAction
{
    /**
     * @param  array<int, array<string, mixed>>  $itemsData
     * @param  bool  $replace  true bo'lsa eski bandlar o'chiriladi
     */
    public function execute(ControlPlan $plan, array $itemsData, bool $replace = false): void
    {
        // delete-then-create — транзаксияда: ўрта йўлда хато бўлса эски бандлар
        // йўқолиб қолмаслиги учун (rollback).
        DB::transaction(function () use ($plan, $itemsData, $replace): void {
            if ($replace) {
                // Edit: eski bandlarni o'chirib, yangidan yaratish
                // (Hujjatlar va activity log saqlanadi — items_documents cascade ishlamaydi)
                $plan->items()->delete();
            }

            foreach ($itemsData as $i => $itemData) {
                $item = $plan->items()->create([
                    'source' => 'control_plan',
                    // Topshiriq tenant'i — rejaning hokimlik_id'si (super-admin global rejimida ham to'g'ri)
                    'hokimlik_id' => $plan->hokimlik_id,
                    'kompleks_id' => $itemData['kompleks_id'] ?? null,
                    'created_by' => $plan->created_by,
                    'item_number' => $itemData['item_number'] ?? null,
                    'section_title' => $itemData['section_title'] ?? null,
                    'task_description' => $itemData['task_description'] ?? '',
                    'implementation' => $itemData['implementation'] ?? null,
                    'funding_source' => $itemData['funding_source'] ?? null,
                    'deadline' => $itemData['deadline'] ?? null,
                    'execution_status' => $itemData['execution_status'] ?? 'not_started',
                    'execution_report' => $itemData['execution_report'] ?? null,
                    'sort_order' => $i,
                ]);

                foreach (($itemData['responsibles'] ?? []) as $resp) {
                    // Polimorfik masъul: 'organization' yoki 'user' (default user — orqaga moslik)
                    $assigneeType = $resp['assignee_type'] ?? 'user';
                    $assigneeId = $resp['assignee_id'] ?? ($resp['user_id'] ?? null);

                    $item->responsibles()->create([
                        'assignee_type' => $assigneeType,
                        'assignee_id' => $assigneeId,
                        'user_id' => $assigneeType === 'user' ? $assigneeId : ($resp['user_id'] ?? null),
                        'responsible_name' => $resp['responsible_name'] ?? '',
                        'responsible_position' => $resp['responsible_position'] ?? null,
                        'is_primary' => (bool) ($resp['is_primary'] ?? false),
                    ]);
                }
            }
        });
    }
}
