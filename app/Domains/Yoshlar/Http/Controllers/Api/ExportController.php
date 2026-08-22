<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Models\EmploymentCase;
use App\Domains\Yoshlar\Models\Task;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Models\YouthCase;
use App\Domains\Yoshlar\Services\AuditLogger;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Domains\Yoshlar\Support\YoshlarScope;
use App\Http\Controllers\Controller;
use App\Support\SimpleXlsx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * F5 — Excel eksport.
 *
 * MUHIM: eksport HAM doiradan oʻtadi — tuman boʻlimi faqat oʻz tumanini
 * yuklab oladi. Aks holda eksport IDOR'ning eng oson yoʻliga aylanardi:
 * ekranda koʻrsatilmagan maʼlumot faylda chiqib ketardi.
 *
 * PII eksportga TUSHMAYDI: PINFL/pasport hech qanday faylga yozilmaydi —
 * u faqat bitta yozuv uchun, jurnal bilan ochiladi.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly YoshlarScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    public function youth(Request $request): Response
    {
        $this->authorizeExport($request);

        $rows = $this->scope->applyYouth(Youth::query(), $request->user())
            ->where('registry_status', 'active')
            ->where('verification_status', 'verified')
            ->orderBy('last_name')
            ->get();

        $districts = $this->districtNames();
        $mahallas = $this->mahallaNames();

        $data = $rows->map(fn (Youth $y): array => [
            $y->full_name,
            $y->age,
            $y->gender,
            $districts[$y->district_id] ?? '',
            $mahallas[$y->mahalla_id] ?? '',
            $y->education_status,
            $y->employment_status,
            $y->is_neet ? 'ha' : '',
            $y->in_patronage ? 'ha' : '',
            $y->phone ?? '',
        ])->all();

        $this->audit->log($request->user(), 'export.youth', 'youth', null, ['rows' => count($data)]);

        return $this->file(
            SimpleXlsx::build(
                ['F.I.Sh', 'Yoshi', 'Jinsi', 'Tuman', 'Mahalla', 'Taʼlim', 'Bandlik', 'NEET', 'Otaliqda', 'Telefon'],
                $data,
                'Reyestr',
            ),
            'yoshlar-reyestri',
        );
    }

    public function tasks(Request $request): Response
    {
        $this->authorizeExport($request, 'yoshlar.task.view');

        $rows = $this->scope->applyTask(Task::query()->with('organization:id,name_lat'), $request->user())
            ->orderBy('deadline')
            ->get();

        $data = $rows->map(fn (Task $t): array => [
            $t->title,
            $t->organization?->name_lat ?? '',
            $t->deadline?->format('Y-m-d') ?? '',
            $t->priority,
            $t->status,
            $t->progress,
            $t->is_overdue ? 'ha' : '',
        ])->all();

        $this->audit->log($request->user(), 'export.tasks', 'task', null, ['rows' => count($data)]);

        return $this->file(
            SimpleXlsx::build(
                ['Topshiriq', 'Mas’ul tashkilot', 'Muddat', 'Ustuvorlik', 'Holat', 'Ijro %', 'Muddat buzilgan'],
                $data,
                'Topshiriqlar',
            ),
            'topshiriqlar',
        );
    }

    public function employment(Request $request): Response
    {
        $this->authorizeExport($request, 'yoshlar.employment.view');

        $districts = $this->districtNames();

        $rows = EmploymentCase::query()
            ->with('youth:id,last_name,first_name,middle_name,birth_date')
            ->when($this->scope->districtIds($request->user()) !== null, function ($q) use ($request) {
                $ids = $this->scope->districtIds($request->user());
                $ids === [] ? $q->whereRaw('1 = 0') : $q->whereIn('district_id', $ids);
            })
            ->orderByDesc('submitted_at')
            ->get();

        $data = $rows->map(fn (EmploymentCase $c): array => [
            $c->youth?->full_name ?? '',
            $districts[$c->district_id] ?? '',
            $c->employer_name,
            $c->employer_inn ?? '',
            $c->position ?? '',
            $c->start_date?->format('Y-m-d') ?? '',
            $c->status,
            $c->tax_district_at?->format('Y-m-d') ?? '',
            $c->tax_province_at?->format('Y-m-d') ?? '',
        ])->all();

        $this->audit->log($request->user(), 'export.employment', 'employment', null, ['rows' => count($data)]);

        return $this->file(
            SimpleXlsx::build(
                ['Yosh', 'Tuman', 'Ish beruvchi', 'INN', 'Lavozim', 'Ishga kirish', 'Holat', 'Tuman soliq', 'Viloyat soliq'],
                $data,
                'Bandlik',
            ),
            'bandlik',
        );
    }

    public function cases(Request $request): Response
    {
        $this->authorizeExport($request, 'yoshlar.case.view');

        $districts = $this->districtNames();
        $ids = $this->scope->districtIds($request->user());

        $rows = YouthCase::query()
            ->with('youth:id,last_name,first_name,middle_name,birth_date')
            ->when($ids !== null, fn ($q) => $ids === [] ? $q->whereRaw('1 = 0') : $q->whereIn('district_id', $ids))
            ->orderByDesc('created_at')
            ->get();

        $data = $rows->map(fn (YouthCase $c): array => [
            $c->youth?->full_name ?? '',
            $districts[$c->district_id] ?? '',
            $c->category,
            $c->title,
            $c->source,
            $c->status,
            $c->sla_deadline?->format('Y-m-d') ?? '',
            $c->resolved_at?->format('Y-m-d') ?? '',
            $c->resolution_note ?? '',
        ])->all();

        $this->audit->log($request->user(), 'export.cases', 'case', null, ['rows' => count($data)]);

        return $this->file(
            SimpleXlsx::build(
                ['Yosh', 'Tuman', 'Toifa', 'Muammo', 'Manba', 'Holat', 'SLA muddati', 'Hal etilgan', 'Yechim'],
                $data,
                'Muammolar',
            ),
            'muammolar',
        );
    }

    private function file(string $binary, string $name): Response
    {
        $filename = $name.'-'.now()->format('Y-m-d').'.xlsx';

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** @return array<string, string> */
    private function districtNames(): array
    {
        return DB::connection('master')->table('districts')->pluck('name_lat', 'id')->all();
    }

    /** @return array<string, string> */
    private function mahallaNames(): array
    {
        return DB::connection('master')->table('mahallas')->pluck('name_lat', 'id')->all();
    }

    private function authorizeExport(Request $request, ?string $extra = null): void
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.export'), 403, 'Eksport huquqi yoʻq.');

        if ($extra !== null) {
            abort_unless($this->access->can($request->user(), $extra), 403, 'Ruxsat yoʻq.');
        }
    }
}
