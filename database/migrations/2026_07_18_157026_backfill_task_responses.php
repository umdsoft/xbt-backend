<?php

declare(strict_types=1);

use App\Models\ControlPlanItem;
use App\Models\ItemDocument;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Eski (sxema qo'shilishidan oldingi) javoblarni task_responses jadvaliga ko'chiradi:
 *  - org foydalanuvchisi yuklagan, task_response_id=NULL hujjatlar → bitta javobga bog'lanadi
 *  - faqat matnli (execution_report) eski javoblar ham timeline'ga tushadi
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        // Bu backfill manba KBT bazasida allaqachon bajarilgan; nusxalanган target
        // bazada task_responses to'ldirilган bo'lishi mumkin. Modellar mavjud
        // bo'lmasa yoki ma'lumot allaqachon mavjud bo'lsa — xavfsiz no-op.
        try {
            $tasks = ControlPlanItem::query()
                ->doesntHave('responses')
                ->where(function ($q) {
                    $q->whereHas('documents', fn ($d) => $d->whereNull('task_response_id'))
                        ->orWhereNotNull('execution_report');
                })
                ->with(['documents', 'responsibles'])
                ->get();

            foreach ($tasks as $task) {
                // Org foydalanuvchisi yuklagan, hali javobga bog'lanmagan hujjatlar
                $orphans = $task->documents->whereNull('task_response_id')->filter(function ($d) {
                    $u = User::find($d->uploaded_by);

                    return $u && $u->organization_id !== null;
                });

                if ($orphans->isNotEmpty()) {
                    $bodyUsed = false;
                    foreach ($orphans->groupBy('uploaded_by') as $uploaderId => $docs) {
                        $u = User::find($uploaderId);
                        $resp = $task->responses()->create([
                            'author_id' => $u?->id,
                            'author_name' => $u?->name,
                            'author_org' => $u?->organization?->name_cyr,
                            'type' => 'response',
                            'body' => $bodyUsed ? null : ($task->execution_report ?: null),
                        ]);
                        $bodyUsed = true;

                        $date = $docs->sortBy('created_at')->first()?->created_at ?? $task->submitted_at ?? now();
                        DB::connection('hr')->table('task_responses')->where('id', $resp->id)->update(['created_at' => $date]);

                        ItemDocument::whereIn('id', $docs->pluck('id'))->update(['task_response_id' => $resp->id]);
                    }
                } elseif (! empty($task->execution_report)) {
                    // Faqat matnli eski javob (updateStatus oqimi)
                    $primary = $task->responsibles->firstWhere('is_primary', true) ?? $task->responsibles->first();
                    $resp = $task->responses()->create([
                        'author_id' => null,
                        'author_name' => $primary?->displayName(),
                        'author_org' => null,
                        'type' => 'response',
                        'body' => $task->execution_report,
                    ]);
                    DB::connection('hr')->table('task_responses')->where('id', $resp->id)
                        ->update(['created_at' => $task->submitted_at ?? now()]);
                }
            }
        } catch (\Throwable $e) {
            // Backfill allaqachon qo'llanilgan yoki modellar mavjud emas — xavfsiz no-op.
        }
    }

    public function down(): void
    {
        // Backfill — qaytarib bo'lmaydi (yangi yozuvlardan ajratib bo'lmaydi)
    }
};
