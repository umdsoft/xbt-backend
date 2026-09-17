<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Models\Anketa;
use App\Domains\Ayollar\Services\DailyChange;
use App\Domains\Ayollar\Support\AnketaFilters;
use App\Domains\Ayollar\Support\AreaFilter;
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

        // Yaroqsiz UUID SQL darajasida 500 berardi — `AreaFilter` ga qara.
        AreaFilter::apply($ids, $request, 'district_id');

        /*
            EHTIYOJ — «ha» deganlar SONI, javoblar taqsimoti emas.

            Avval bu yer `answers->>'qN'` bo'yicha guruhlardi va ekranga
            «true / false» chiqarardi. Ikki sababdan yaroqsiz edi:

              1. «Yo'q» degan 4 900 kishi ehtiyoj emas — ular ro'yxatni
                 to'ldirib, haqiqiy sonni ko'mib yuborardi;
              2. 17-band OBYEKT saqlaydi (`{istak, joy}`) va `->>` uni
                 butun JSON satr sifatida qaytarardi — ekranda
                 `{"istak":true,"joy":"texnikum"}` ko'rinardi.

            Endi har band uchun: nechta javob bor, nechtasida EHTIYOJ
            bor, ulushi qancha, va ehtiyoj qayerda to'plangan.
        */
        $result = [];

        foreach (Rules::needQuestions() as $q) {
            $result[] = $this->needGroup($ids, $q, $request);
        }

        return response()->json(['needs' => $result]);
    }

    /**
     * Bitta ehtiyoj bandining kesimi.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Anketa>  $ids
     * @return array<string, mixed>
     */
    private function needGroup($ids, int $question, Request $request): array
    {
        $isGrouped = Rules::questionGroups($question) !== [];

        // JSONB yo'li reyestr filtri bilan AYNAN BIR XIL bo'lishi shart:
        // aks holda xaritadagi son bilan bosilgandan keyin ochilgan
        // ro'yxat uzunligi mos kelmasdi.
        $flag = AnketaFilters::needFlagSql($question);

        $base = DB::connection('ayollar')->table('anketas')
            ->whereIn('id', $ids)
            ->whereRaw("{$flag} is not null");

        $total = (clone $base)->count();

        $yesFilter = fn ($q) => $q->whereRaw(AnketaFilters::needYesSql($question));

        $need = (clone $base)->tap($yesFilter)->count();

        return [
            'question' => $question,
            'title' => Rules::questionTitle($question),
            'total' => $total,
            'need' => $need,
            'share' => $total > 0 ? round($need * 100 / $total, 1) : 0.0,
            'breakdown' => $isGrouped ? $this->needBreakdown($base, $question, $yesFilter) : [],
            'areas' => $this->needAreas($base, $yesFilter, $request),
        ];
    }

    /**
     * Guruhli bandning ichki taqsimoti — 17-bandda «qayerda o'qimoqchi».
     *
     * Aynan shu raqam rejalashtirishga kerak: «kasb-hunar istagi 1 200»
     * degan son bilan hech narsa qilib bo'lmaydi, «texnikumda 700,
     * monomarkazda 300» esa joy va o'rin sonini aytadi.
     *
     * @return array<int, array<string, mixed>>
     */
    private function needBreakdown($base, int $question, callable $yesFilter): array
    {
        $rows = (clone $base)->tap($yesFilter)
            ->whereRaw("answers -> 'q{$question}' ->> 'joy' is not null")
            ->selectRaw("answers -> 'q{$question}' ->> 'joy' as v, count(*) as c")
            ->groupBy('v')->orderByDesc('c')->get();

        return $rows->map(fn ($r) => [
            'value' => $r->v,
            'label' => Rules::label((string) $r->v),
            'count' => (int) $r->c,
        ])->all();
    }

    /**
     * Ehtiyoj QAYERDA to'plangan.
     *
     * Doiraga qarab kesim o'zgaradi: viloyat rahbari tumanlarni
     * ko'radi, tuman rahbari o'z MFYlarini. MFY faoliga bu ro'yxat
     * ortiqcha — unda bitta hudud bor.
     *
     * @return array<int, array<string, mixed>>
     */
    private function needAreas($base, callable $yesFilter, Request $request): array
    {
        $level = $this->access->scopeLevel($request->user());
        $byMahalla = $level !== AyollarAccess::SCOPE_REGION || $request->filled('district_id');

        if ($level === AyollarAccess::SCOPE_MAHALLA) {
            return [];
        }

        $column = $byMahalla ? 'mahalla_id' : 'district_id';
        $table = $byMahalla ? 'master.mahallas' : 'master.districts';

        /*
            TAXALLUS `area_id` — `id` EMAS.

            `anketas` jadvalining O'ZIDA `id` ustuni bor. `... as id`
            deb nomlab `group by id` yozilganda PostgreSQL chiqish
            taxallusini emas, KIRISH ustunini oladi va har anketa
            alohida guruh bo'lib qoladi. Ekranda bu shunday ko'rindi:
            bitta tuman ro'yxatda uch marta, har safar «1» bilan.
        */
        $rows = (clone $base)->tap($yesFilter)
            ->selectRaw("{$column} as area_id, count(*) as c")
            ->groupBy($column)->orderByDesc('c')->limit(10)->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = DB::connection('master')->table($table)
            ->whereIn('id', $rows->pluck('area_id')->all())
            ->pluck('name_lat', 'id');

        return $rows->map(fn ($r) => [
            'id' => (string) $r->area_id,
            'name' => $names[$r->area_id] ?? '—',
            'count' => (int) $r->c,
            'level' => $byMahalla ? 'mahalla' : 'district',
        ])->all();
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

        // Qurilma va oxirgi kirish — «kim yordamga muhtoj» savoliga
        // anketa sonidan ko'ra ANIQROQ javob beradi: bir hafta
        // kirmagan faolda son shunchaki 0 bo'lib qoladi va sabab
        // (planshet buzuq? xodim almashgan?) noma'lum qolardi.
        $devices = DB::connection('ayollar')->table('staff')
            ->whereIn('user_id', $rows->pluck('created_by')->filter()->all())
            ->get(['user_id', 'last_device_id', 'last_seen_at'])
            ->keyBy('user_id');

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
                'device_id' => $devices[$r->created_by]->last_device_id ?? null,
                'last_seen_at' => $devices[$r->created_by]->last_seen_at ?? null,
                // Sifat = to'liq anketalar ulushi. Bitta son bilan kim
                // yordamga muhtojligini ko'rsatadi.
                'quality' => (int) $r->total > 0
                    ? round(100 * (1 - ((int) $r->incomplete / (int) $r->total)))
                    : null,
            ])->sortBy('quality')->values(),
        ]);
    }

    /**
     * KUNLIK O'ZGARISH — tuman va MFY kesimida.
     *
     * «Tumanlar kesimi» jadvali JAMI sonni beradi va u savolga javob
     * bermaydi: raqam BUGUN qancha o'sdi? Hisob-kitob va tanlovlar
     * `DailyChange` da — kontroller faqat ruxsatni tekshiradi.
     */
    public function daily(Request $request, DailyChange $daily): JsonResponse
    {
        $this->authorize($request, 'ayollar.view');

        return response()->json($daily->build($request));
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
