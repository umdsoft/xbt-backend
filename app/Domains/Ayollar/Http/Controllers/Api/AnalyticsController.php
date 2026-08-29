<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Models\Anketa;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Domains\Ayollar\Support\AyollarScope;
use App\Domains\Ayollar\Support\Rules;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tahlil — ehtiyojlar xaritasi va faollar monitoringi.
 *
 * Ikkalasi ham AGREGAT: bu yerdan hech qachon ism yoki JShShIR
 * qaytarilmaydi. Viloyat tahlilchisi qaror qabul qilish uchun SONLARNI
 * ko'radi, shaxsni emas.
 */
class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AyollarAccess $access,
        private readonly AyollarScope $scope,
    ) {}

    /**
     * Ehtiyojlar xaritasi — «istak» savollarining agregatsiyasi.
     *
     * Bu savollar (16–19, 21, 26, 28) BALANSDA YO'Q: ular toifani
     * o'zgartirmaydi. Lekin qaror qabul qilish uchun eng qimmatli qism —
     * balans «hozir qanday» ni ko'rsatadi, ehtiyojlar esa «nima kerak» ni.
     */
    public function needs(Request $request): JsonResponse
    {
        $this->authorize($request, 'ayollar.analytics.view');

        $ids = Anketa::query()->countable()->select('id');
        $this->scope->apply($ids, $request->user());

        if ($request->filled('district_id')) {
            $ids->where('district_id', $request->string('district_id')->toString());
        }

        $questions = Rules::needQuestions();
        $result = [];

        foreach ($questions as $q) {
            // JSONB ichidagi javob bo'yicha guruhlash. `answers->>'q16'`
            // GIN indeksdan foydalanmaydi, lekin bu so'rov kunda bir necha
            // marta chaqiriladi va natija keshlanadi — optimallashtirish
            // hozircha erta bo'lardi.
            $rows = DB::connection('ayollar')->table('anketas')
                ->whereIn('id', $ids)
                ->whereRaw("answers ->> ? is not null", ["q{$q}"])
                ->selectRaw('answers ->> ? as answer, count(*) as c', ["q{$q}"])
                ->groupBy('answer')
                ->orderByDesc('c')
                ->limit(20)
                ->get();

            $result[] = [
                'question' => $q,
                'answers' => $rows->map(fn ($r) => ['value' => $r->answer, 'count' => (int) $r->c])->all(),
            ];
        }

        return response()->json(['needs' => $result]);
    }

    /**
     * Faollar monitoringi — anketa sifati va sur'ati.
     *
     * Maqsad — kim YORDAMGA MUHTOJ ekanini ko'rish, kim yomon ishlashini
     * emas. Shuning uchun ko'rsatkichlar «to'liq bo'lmagan anketa ulushi»
     * va «sinxronlanmagan yozuvlar» — ikkalasi ham yordam kerakligining
     * belgisi (tayyorgarlik yoki tarmoq muammosi).
     */
    public function activists(Request $request): JsonResponse
    {
        $this->authorize($request, 'ayollar.activists.view');

        $query = Anketa::query();
        $this->scope->apply($query, $request->user());

        $rows = $query
            ->selectRaw('created_by, mahalla_id')
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where category = 'incomplete') as incomplete")
            ->selectRaw("count(*) filter (where status = 'draft') as drafts")
            ->selectRaw('count(*) filter (where synced_at is null) as unsynced')
            ->selectRaw('max(filled_at) as last_filled')
            ->whereNotNull('created_by')
            ->groupBy('created_by', 'mahalla_id')
            ->get();

        $userNames = DB::connection('auth')->table('users')
            ->whereIn('id', $rows->pluck('created_by')->filter()->all())
            ->pluck('name', 'id');

        $mahallaNames = DB::connection('master')->table('mahallas')
            ->whereIn('id', $rows->pluck('mahalla_id')->filter()->all())
            ->pluck('name_lat', 'id');

        return response()->json([
            'activists' => $rows->map(fn ($r) => [
                'user_id' => $r->created_by,
                'name' => $userNames[$r->created_by] ?? '—',
                'mahalla_id' => $r->mahalla_id,
                'mahalla_name' => $mahallaNames[$r->mahalla_id] ?? '—',
                'total' => (int) $r->total,
                'incomplete' => (int) $r->incomplete,
                'drafts' => (int) $r->drafts,
                'unsynced' => (int) $r->unsynced,
                'last_filled' => $r->last_filled,
                // Sifat = to'liq anketalar ulushi. Bitta son bilan kim
                // yordamga muhtojligini ko'rsatadi.
                'quality' => (int) $r->total > 0
                    ? round(100 * (1 - ((int) $r->incomplete / (int) $r->total)))
                    : null,
            ])->sortBy('quality')->values(),
        ]);
    }

    /**
     * Yig'ish sur'ati — kunlik anketa soni.
     *
     * Boshqaruv panelidagi grafik. Kutubxonasiz SVG bilan chiziladi
     * (promt §2), shuning uchun API xom nuqtalarni qaytaradi.
     */
    public function pace(Request $request): JsonResponse
    {
        $this->authorize($request, 'ayollar.view');

        $query = Anketa::query();
        $this->scope->apply($query, $request->user());

        $days = min((int) $request->integer('days', 30), 120);

        $rows = $query
            ->where('filled_at', '>=', now()->subDays($days))
            ->selectRaw('date(filled_at) as day, count(*) as c')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return response()->json([
            'pace' => $rows->map(fn ($r) => ['day' => $r->day, 'count' => (int) $r->c])->all(),
        ]);
    }

    /**
     * Qizil toifa tarkibi — belgilar bo'yicha.
     *
     * ISMLAR YO'Q. Ular alohida endpoint'da (`redList`), faqat 3 rolga.
     */
    public function redComposition(Request $request): JsonResponse
    {
        $this->authorize($request, 'ayollar.view');

        $ids = Anketa::query()->countable()->select('id');
        $this->scope->apply($ids, $request->user());

        $rows = DB::connection('ayollar')->table('anketa_red_flags')
            ->whereIn('anketa_id', $ids)
            ->selectRaw('flag_code, count(*) as c')
            ->groupBy('flag_code')
            ->orderByDesc('c')
            ->get();

        return response()->json([
            'composition' => $rows->map(fn ($r) => ['code' => $r->flag_code, 'count' => (int) $r->c])->all(),
        ]);
    }

    private function authorize(Request $request, string $permission): void
    {
        if (! $this->access->can($request->user(), $permission)) {
            abort(403, 'Ruxsat yo‘q.');
        }
    }
}
