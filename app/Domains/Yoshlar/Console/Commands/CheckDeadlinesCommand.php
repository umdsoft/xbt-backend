<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Console\Commands;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Task;
use App\Domains\Yoshlar\Models\YouthCase;
use App\Domains\Yoshlar\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Muddat nazorati va ESKALATSIYA (TZ 5.2, 6).
 *
 * Uch bosqich:
 *   1. Muddat yaqin (3 kun) -> IJROCHI tashkilotga eslatma.
 *   2. Muddat buzildi     -> ijrochiga + YUQORI tashkilotga (eskalatsiya).
 *   3. Muammo SLA buzildi -> mas'ul tashkilotga.
 *
 * NEGA ESKALATSIYA YUQORIGA: ijrochiga eslatma yuborish yetarli emas —
 * u allaqachon kechiktirgan. Yuqori bo'g'in ko'rmasa, muddat buzilishi
 * hech kimning ishiga aylanmaydi.
 *
 * Takroriy xabar yuborilmaydi: `NotificationService` o'qilmagan bir xil
 * yozuv bo'lsa yangisini yaratmaydi.
 */
class CheckDeadlinesCommand extends Command
{
    protected $signature = 'yoshlar:check-deadlines {--dry-run : Faqat sonini koʻrsatadi}';

    protected $description = 'Muddati yaqin va buzilgan topshiriq/muammolarni aniqlab ogohlantiradi';

    public function handle(NotificationService $notify): int
    {
        $dry = (bool) $this->option('dry-run');
        $today = now()->startOfDay();

        $near = Task::query()
            ->where('status', '!=', 'tasdiqlandi')
            ->whereDate('deadline', '>=', $today->toDateString())
            ->whereDate('deadline', '<', $today->copy()->addDays(Task::AMBER_DAYS)->toDateString())
            ->get();

        $overdue = Task::query()->overdue()->get();

        $overdueCases = YouthCase::query()->open()
            ->whereNotNull('sla_deadline')
            ->whereDate('sla_deadline', '<', $today->toDateString())
            ->get();

        $this->line('Muddat yaqin: '.$near->count());
        $this->line('Muddati oʻtgan topshiriq: '.$overdue->count());
        $this->line('SLA buzilgan muammo: '.$overdueCases->count());

        if ($dry) {
            $this->info('dry-run — xabar yuborilmadi.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($near as $task) {
            $sent += $notify->notifyOrganization($task->assigned_org_id, 'task.deadline_near', [
                'title' => 'Topshiriq muddati yaqin',
                'body' => $task->title.' — '.$task->days_left.' kun qoldi',
                'link' => '/topshiriqlar/'.$task->id,
                'entity_type' => 'task',
                'entity_id' => $task->id,
            ]);
        }

        foreach ($overdue as $task) {
            $sent += $notify->notifyOrganization($task->assigned_org_id, 'task.overdue', [
                'title' => 'Topshiriq muddati buzildi',
                'body' => $task->title,
                'link' => '/topshiriqlar/'.$task->id,
                'entity_type' => 'task',
                'entity_id' => $task->id,
            ]);

            // ESKALATSIYA: yuqori tashkilot va viloyat yoshlar boshqarmasi.
            $parentId = Organization::query()->whereKey($task->assigned_org_id)->value('parent_id');

            if ($parentId !== null) {
                $sent += $notify->notifyOrganization((string) $parentId, 'task.overdue', [
                    'title' => 'Quyi tashkilotda muddat buzildi',
                    'body' => $task->title,
                    'link' => '/topshiriqlar/'.$task->id,
                    'entity_type' => 'task',
                    'entity_id' => $task->id,
                ]);
            }

            $sent += $notify->notifyRole('yoshlar_boshqarma', 'task.overdue', [
                'title' => 'Muddati buzilgan topshiriq',
                'body' => $task->title,
                'link' => '/topshiriqlar/'.$task->id,
                'entity_type' => 'task',
                'entity_id' => $task->id,
            ]);
        }

        foreach ($overdueCases as $case) {
            if ($case->assigned_org_id === null) {
                continue;
            }

            $sent += $notify->notifyOrganization($case->assigned_org_id, 'case.overdue', [
                'title' => 'Muammo SLA muddati buzildi',
                'body' => $case->title,
                'link' => '/muammolar',
                'entity_type' => 'case',
                'entity_id' => $case->id,
            ]);
        }

        $this->info("Yuborilgan bildirishnoma: {$sent}");

        return self::SUCCESS;
    }
}
