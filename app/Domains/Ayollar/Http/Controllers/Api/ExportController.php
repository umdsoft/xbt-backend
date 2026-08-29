<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Models\Anketa;
use App\Domains\Ayollar\Models\Balance;
use App\Domains\Ayollar\Models\DistrictBalance;
use App\Domains\Ayollar\Models\MahallaBalance;
use App\Domains\Ayollar\Models\Metric;
use App\Domains\Ayollar\Models\RegionBalance;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Domains\Ayollar\Support\AyollarScope;
use App\Http\Controllers\Controller;
use App\Domains\Ayollar\Services\AuditLogger;
use App\Domains\Ayollar\Services\QrService;
use App\Domains\Ayollar\Services\SensitiveAccessService;
use App\Domains\Ayollar\Support\Rules;
use App\Support\SimpleXlsx;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Excel eksport — SUV BELGISI bilan.
 *
 * MUHIM: eksport HAM doiradan o'tadi. Aks holda u IDOR'ning eng oson
 * yo'liga aylanardi — ekranda ko'rsatilmagan ma'lumot faylda chiqib
 * ketardi.
 *
 * PII EKSPORTGA TUSHMAYDI: JShShIR, pasport va telefon hech qanday
 * faylga yozilmaydi. Ular faqat bitta yozuv uchun, jurnal bilan ochiladi.
 * Excel esa nazoratsiz ko'chiriladi — bir marta yuklangan fayl keyin
 * qayerga borishini hech kim bilmaydi.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly AyollarAccess $access,
        private readonly AyollarScope $scope,
        private readonly QrService $qr,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Balans shakli — `metric_registry` dan generatsiya qilinadi.
     *
     * Kodda hardcode QILINMAYDI (promt §14): shakl o'zgarsa, lug'at
     * yangilanadi va uchala daraja bir vaqtda to'g'rilanadi.
     */
    public function balance(Request $request, string $type, string $id): Response
    {
        $this->assertCanExport($request);

        $balance = match ($type) {
            'mahalla' => MahallaBalance::query()->findOrFail($id),
            'district' => DistrictBalance::query()->findOrFail($id),
            'region' => RegionBalance::query()->findOrFail($id),
            default => abort(404),
        };

        $this->assertInScope($request, $balance);

        $metrics = $balance->metrics ?? [];

        $rows = Metric::query()->forForm($balance->levelCode())->get()
            ->map(fn (Metric $m) => [
                $m->name_cyr,
                $m->name_lat,
                $this->categoryLabel($m->category),
                (int) ($metrics[$m->code] ?? 0),
            ])
            ->all();

        // Yakuniy qatorlar — tekshiruv tengligini FAYLDA ham ko'rsatadi.
        $rows[] = ['', '', '', ''];
        $rows[] = ['ЖАМИ', 'JAMI', '', $balance->total];
        $rows[] = ['Яшил', 'Yashil', 'green', $balance->green];
        $rows[] = ['Сариқ', 'Sariq', 'yellow', $balance->yellow];
        $rows[] = ['Қизил (устма-уст)', 'Qizil (ustma-ust)', 'red', $balance->red];

        $this->logExport($request, 'balance', (string) $balance->id);

        return $this->xlsx(
            ['Кўрсаткич', 'Ko‘rsatkich', 'Toifa', 'Soni'],
            $this->watermark($rows, $request),
            "balans-{$type}-{$balance->period_year}-{$balance->period_month}.xlsx",
        );
    }

    /**
     * Anketalar reyestri.
     *
     * Ustunlar ATAYLAB PII'siz: F.I.Sh. ham YO'Q. Ro'yxatni tahlil qilish
     * uchun ro'yxat raqami, MFY, toifa va yosh guruhi yetarli; ism kerak
     * bo'lsa, u tizimda bitta-bitta ochiladi va jurnalga tushadi.
     */
    public function registry(Request $request): Response
    {
        $this->assertCanExport($request);

        $query = Anketa::query()->countable();
        $this->scope->apply($query, $request->user());

        if ($request->filled('district_id')) {
            $query->where('district_id', $request->string('district_id')->toString());
        }

        $mahallas = DB::connection('master')->table('mahallas')->pluck('name_lat', 'id');
        $districts = DB::connection('master')->table('districts')->pluck('name_lat', 'id');
        $metricNames = Metric::query()->pluck('name_lat', 'code');

        $rows = [];

        // CHUNK: reyestrda ~1 mln yozuv bo'lishi mumkin. `get()` ularning
        // hammasini Eloquent modeliga aylantirib xotiraga solardi.
        $query->orderBy('reg_number')->chunk(1000, function ($chunk) use (&$rows, $mahallas, $districts, $metricNames): void {
            foreach ($chunk as $a) {
                $rows[] = [
                    $a->reg_number,
                    $districts[$a->district_id] ?? '—',
                    $mahallas[$a->mahalla_id] ?? '—',
                    $this->ageGroupLabel($a->age_group),
                    $this->categoryLabel($a->category),
                    $metricNames[$a->balance_row] ?? '—',
                    $a->filled_at?->format('d.m.Y') ?? '',
                ];
            }
        });

        $this->logExport($request, 'registry');

        return $this->xlsx(
            ['Ro‘yxat raqami', 'Tuman', 'MFY', 'Yosh guruhi', 'Toifa', 'Balans qatori', 'To‘ldirilgan'],
            $this->watermark($rows, $request),
            'anketalar-reyestri.xlsx',
        );
    }

    /**
     * Anketa PDF — QR bilan rasmiy hujjat (promt §7).
     *
     * V BO'LIM (ijtimoiy nazorat) PDF'GA TUSHMAYDI, hatto `pii.reveal`
     * huquqi bor foydalanuvchida ham. Sabab: PDF bosiladi, papkaga
     * qo'yiladi va nazoratsiz ko'chiriladi — zo'ravonlik yoki
     * narkologiya hisobi haqidagi javob qog'ozda yurishi mumkin
     * emas. Qizil BELGILAR ro'yxati qoladi (kim bilan ishlash kerak),
     * lekin javobning o'zi emas.
     */
    public function anketaPdf(Request $request, string $id): Response
    {
        $this->assertCanExport($request);

        $query = Anketa::query()->with(['woman', 'redFlags']);
        $this->scope->apply($query, $request->user());
        $anketa = $query->findOrFail($id);

        $this->logExport($request, 'anketa_pdf', (string) $anketa->id);

        $metricNames = Metric::query()->pluck('name_lat', 'code');

        $html = view('ayollar.anketa-pdf', [
            'anketa' => $anketa,
            'qr' => $this->qr->runs($anketa),
            'district' => DB::connection('master')->table('districts')
                ->where('id', $anketa->district_id)->value('name_lat'),
            'mahalla' => DB::connection('master')->table('mahallas')
                ->where('id', $anketa->mahalla_id)->value('name_lat'),
            'ageGroup' => $this->ageGroupLabel($anketa->age_group),
            'categoryLabel' => $this->categoryLabel($anketa->category),
            'balanceRow' => $metricNames[$anketa->balance_row] ?? null,
            'redFlags' => $anketa->redFlags->map(fn ($f) => $metricNames[$f->flag_code] ?? $f->flag_code)->all(),
            'sections' => $this->pdfSections($anketa),
            // V bo'lim to'ldirilgan bo'lsa, uni JIMGINA tashlab ketmaymiz:
            // hujjatni o'qigan odam anketa to'liq emasdek o'ylamasligi kerak.
            'hasSensitive' => $this->hasSensitiveAnswers($anketa),
            'filledByPosition' => DB::connection('ayollar')->table('staff')
                ->where('user_id', $anketa->created_by)->value('position'),
            // Suv belgisi — PDF ham nazoratsiz ko'chiriladi (promt §6.5).
            'downloadedBy' => $request->user()->name.' ('.$request->user()->login.')',
            'downloadedAt' => now()->format('d.m.Y H:i'),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        // Default DejaVu Sans — kirill va lotin kengaytmasini qoplaydi.
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$anketa->reg_number.'.pdf"',
        ]);
    }

    /**
     * PDF uchun javoblar — bo'limlar bo'yicha, V BO'LIMSIZ.
     *
     * @return array<int, array{number: int, title: string, items: array<int, array<string, mixed>>}>
     */
    private function pdfSections(Anketa $anketa): array
    {
        $answers = $anketa->answers ?? [];
        $sensitive = Rules::sensitiveQuestions();
        $out = [];

        foreach (Rules::sections() as $section) {
            $items = [];

            for ($q = $section['from']; $q <= $section['to']; $q++) {
                if (in_array($q, $sensitive, true) || ! array_key_exists("q{$q}", $answers)) {
                    continue;
                }

                $items[] = [
                    'number' => $q,
                    'title' => $q.'-savol',
                    'value' => $this->displayAnswer($answers["q{$q}"]),
                ];
            }

            $out[] = [
                'number' => $section['number'],
                'title' => $section['title_lat'],
                'items' => $items,
            ];
        }

        return $out;
    }

    /** V bo'lim savollaridan birortasi to'ldirilganmi. */
    private function hasSensitiveAnswers(Anketa $anketa): bool
    {
        $answers = $anketa->answers ?? [];

        foreach (Rules::sensitiveQuestions() as $q) {
            $value = $answers["q{$q}"] ?? null;

            if (is_array($value) ? array_filter($value) !== [] : ! empty($value)) {
                return true;
            }
        }

        return false;
    }

    private function displayAnswer(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Ha' : 'Yo‘q';
        }

        if (is_array($value)) {
            $on = array_keys(array_filter($value));

            return $on === [] ? '—' : implode(', ', $on);
        }

        return (string) $value;
    }

    // ---------------------------------------------------------------

    /**
     * Suv belgisi — kim va qachon yuklaganini FAYL ICHIGA yozadi.
     *
     * Promt §6.5 talabi. Fayl tarqalsa, uning manbasi aniqlanadi. Bu
     * texnik himoya emas (qatorni o'chirib tashlash mumkin), lekin
     * beparvolikdan kelib chiqadigan tarqalishni sezilarli kamaytiradi:
     * odam o'z ismi yozilgan faylni osonlikcha uzatmaydi.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, array<int, mixed>>
     */
    private function watermark(array $rows, Request $request): array
    {
        $rows[] = [];
        $rows[] = [
            'Юклаб олди: '.$request->user()->name.' ('.$request->user()->login.')',
            'Сана: '.now()->format('d.m.Y H:i'),
            'IP: '.$request->ip(),
            'Ayollar Balansi · Digital Xorazm',
        ];

        return $rows;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function xlsx(array $headers, array $rows, string $filename): Response
    {
        return response(
            SimpleXlsx::build($headers, $rows, 'Balans'),
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ],
        );
    }

    private function assertCanExport(Request $request): void
    {
        if (! $this->access->can($request->user(), 'ayollar.export')) {
            abort(403, 'Eksportga ruxsat yo‘q.');
        }
    }

    /**
     * Eksport jurnalga tushadi.
     *
     * Fayl nazoratsiz ko'chiriladi — kim va nimani yuklaganini bilish
     * suv belgisidan MUSTAQIL ikkinchi iz. Suv belgisi qatorini
     * o'chirish mumkin, jurnalni esa yo'q.
     */
    private function logExport(Request $request, string $kind, ?string $entityId = null): void
    {
        $this->audit->log($request->user(), 'export.'.$kind, 'export', $entityId, [
            'filters' => array_filter($request->only(['district_id', 'mahalla_id', 'category'])),
        ], $request);
    }

    private function assertInScope(Request $request, Balance $balance): void
    {
        $ownerId = (string) $balance->{$balance->ownerKey()};

        $ok = match ($balance->levelCode()) {
            'mahalla' => $this->scope->canAccessMahalla(
                $request->user(),
                $ownerId,
                (string) DB::connection('master')->table('mahallas')->where('id', $ownerId)->value('district_id'),
            ),
            'district' => $this->scope->canAccessDistrict($request->user(), $ownerId),
            default => $this->access->seesEverything($request->user()),
        };

        if (! $ok) {
            abort(403, 'Bu balans sizning doirangizda emas.');
        }
    }

    private function categoryLabel(?string $category): string
    {
        return match ($category) {
            'green' => 'Yashil',
            'yellow' => 'Sariq',
            'red' => 'Qizil',
            'age' => 'Yosh',
            'meta' => '',
            'incomplete' => 'To‘liq emas',
            default => (string) $category,
        };
    }

    private function ageGroupLabel(?string $group): string
    {
        return match ($group) {
            '0_2' => '0–2',
            '3_6' => '3–6',
            '7_17' => '7–17',
            '18_up' => '18+',
            default => (string) $group,
        };
    }
}
