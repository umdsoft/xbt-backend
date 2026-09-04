<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Models\Anketa;
use App\Domains\Ayollar\Models\AuditLog;
use App\Domains\Ayollar\Models\Metric;
use App\Domains\Ayollar\Models\SensitiveAccessLog;
use App\Domains\Ayollar\Models\Woman;
use App\Domains\Ayollar\Services\AuditLogger;
use App\Domains\Ayollar\Services\PiiCipher;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Domains\Ayollar\Support\Rules;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ADMINISTRATOR — jurnallar va metrikalar lug'ati.
 *
 * `admin` rolida `pii.reveal` va `red.names` ATAYLAB YO'Q: tizimni
 * boshqarish shaxsiy ma'lumotni ko'rish huquqini bermaydi. Shuning
 * uchun bu yerdagi jurnallar ham KIM NIMA QILGANINI ko'rsatadi,
 * ma'lumotning O'ZINI emas.
 */
class AdminController extends Controller
{
    public function __construct(private readonly AyollarAccess $access) {}

    /**
     * Amallar jurnali.
     *
     * Jurnal — nazorat vositasi, shuning uchun u O'ZGARTIRILMAYDI va
     * o'chirilmaydi: faqat o'qish endpoint'i bor.
     */
    public function audit(Request $request): JsonResponse
    {
        $this->authorize($request, 'ayollar.audit.view');

        $query = AuditLog::query();

        foreach (['action', 'entity_type', 'entity_id', 'user_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter)->toString());
            }
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from'));
        }

        $page = $query->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 50), 200));

        // Foydalanuvchi nomlari BIR so'rovda — sahifadagi 50 qator uchun
        // 50 ta so'rov qilish (N+1) jurnalni sekin qilardi.
        $names = DB::connection('auth')->table('users')
            ->whereIn('id', $page->getCollection()->pluck('user_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $page->getCollection()->transform(function (AuditLog $row) use ($names) {
            $row->setAttribute('user_name', $names[$row->user_id] ?? null);

            return $row;
        });

        return response()->json($page);
    }

    /**
     * Maxfiy maydonga kirish jurnali.
     *
     * ALOHIDA endpoint: bu «kim nima qildi» emas, «kim NIMANI KO'RDI».
     * Ikkisini bitta ro'yxatga qo'shish nozik hodisalarni oddiy
     * amallar orasida ko'mib yuborardi.
     */
    public function sensitiveAccess(Request $request): JsonResponse
    {
        $this->authorize($request, 'ayollar.audit.view');

        $page = SensitiveAccessLog::query()
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->string('user_id')->toString()))
            ->when($request->filled('field'), fn ($q) => $q->where('field', $request->string('field')->toString()))
            ->orderByDesc('accessed_at')
            ->paginate(min((int) $request->integer('per_page', 50), 200));

        $names = DB::connection('auth')->table('users')
            ->whereIn('id', $page->getCollection()->pluck('user_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $page->getCollection()->transform(function (SensitiveAccessLog $row) use ($names) {
            $row->setAttribute('user_name', $names[$row->user_id] ?? null);

            return $row;
        });

        return response()->json($page);
    }

    /**
     * Metrikalar lug'ati — balans shakllarining manbai.
     *
     * Ko'rish `audit.view` bilan (admin va tekshiruvchi uchun),
     * tahrirlash esa `metric.manage` bilan.
     */
    public function metrics(Request $request): JsonResponse
    {
        $this->authorize($request, 'ayollar.view');

        return response()->json([
            'metrics' => Metric::query()->orderBy('sort_order')->get(),
            'can_manage' => $this->access->can($request->user(), 'ayollar.metric.manage'),
        ]);
    }

    /**
     * Lug'at qatorini yangilaydi.
     *
     * `code` O'ZGARTIRILMAYDI — u `rules.json` dagi `balance_row` bilan
     * bog'langan va uni o'zgartirish barcha saqlangan balanslarni
     * yaroqsiz qilardi. Faqat nom, tartib va qaysi shaklga kirishi
     * tahrirlanadi.
     */
    public function updateMetric(Request $request, string $code): JsonResponse
    {
        $this->authorize($request, 'ayollar.metric.manage');

        $metric = Metric::query()->where('code', $code)->firstOrFail();

        $metric->update($request->validate([
            'name_lat' => ['sometimes', 'string', 'max:300'],
            'name_cyr' => ['sometimes', 'string', 'max:300'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'in_mahalla_form' => ['sometimes', 'boolean'],
            'in_district_form' => ['sometimes', 'boolean'],
            'in_region_form' => ['sometimes', 'boolean'],
            'owner_org_code' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]));

        app(AuditLogger::class)->log(
            $request->user(),
            'metric.updated',
            'metric',
            (string) $metric->id,
            ['code' => $code],
            $request,
        );

        return response()->json(['metric' => $metric->fresh()]);
    }

    /**
     * Tizim salomatligi — deploy va nosozlik tekshiruvi uchun.
     *
     * NEGA KERAK: prodda «nega toifa noto'g'ri?» degan savol
     * ko'pincha «qoida fayli eskirgan» yoki «lug'at seeder
     * yurgizilmagan» bo'lib chiqadi. Buni ekrandan ko'rish
     * serverga SSH bilan kirishdan tez.
     */
    public function health(Request $request): JsonResponse
    {
        $this->authorize($request, 'ayollar.admin');

        $rules = Rules::all();
        $ladderRows = array_column($rules['category_ladder'], 'balance_row');
        $registryCodes = Metric::query()->pluck('code')->all();

        // Zinapoyada bor, lug'atda YO'Q qator — shakl bo'sh chiqadi.
        $missing = array_values(array_diff($ladderRows, $registryCodes));

        return response()->json([
            'rules_version' => $rules['version'],
            'ladder_steps' => count($rules['category_ladder']),
            'red_flags' => count($rules['red_flags']),
            'metrics' => count($registryCodes),
            // Bo'sh bo'lishi SHART: aks holda balans qatorlari yo'qoladi.
            'missing_metrics' => $missing,
            'pii_dedicated_key' => app(PiiCipher::class)->usesDedicatedKey(),
            'anketas' => Anketa::query()->count(),
            'women' => Woman::query()->count(),
        ]);
    }

    private function authorize(Request $request, string $permission): void
    {
        if (! $this->access->can($request->user(), $permission)) {
            abort(403, 'Ruxsat yo‘q.');
        }
    }
}
