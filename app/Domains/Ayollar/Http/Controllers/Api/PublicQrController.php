<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Models\Anketa;
use App\Domains\Ayollar\Models\BalanceSignature;
use App\Domains\Ayollar\Services\QrService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/a/{qr_token}?v={hmac}` — OCHIQ hujjat tekshiruv sahifasi.
 *
 * AUTENTIFIKATSIYASIZ ochiladi: QR bosilgan qog'ozda va uni skanerlagan
 * har kim (masalan tekshiruvchi) hujjat haqiqiyligini ko'ra olishi kerak.
 *
 * SHAXSIY MA'LUMOT KO'RSATILMAYDI (promt §7): ism, JShShIR, telefon,
 * manzil — hech biri. Faqat hujjat FAKTLARI: ro'yxat raqami, sana, MFY,
 * toifa belgisi, kim to'ldirgan (LAVOZIM, ism emas) va imzolar zanjiri.
 *
 * NEGA IMZO KERAK: token 6 belgi, ya'ni 32^6 ≈ 1 mlrd variant. Bu
 * ko'rinadi, lekin tokenni topgan odam qo'shni tokenlarni sinab
 * ko'rishi mumkin. HMAC bu yo'lni yopadi: imzosiz havola OCHILMAYDI.
 */
class PublicQrController extends Controller
{
    public function __construct(private readonly QrService $qr) {}

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $signature = $request->string('v')->toString();

        $anketa = Anketa::query()
            ->where('qr_token', strtoupper($token))
            ->first();

        // Topilmagan va imzosi noto'g'ri holatlar BIR XIL javob beradi.
        //
        // Farqlansa, hujum qiluvchi «token bor, imzo xato» javobidan
        // mavjud tokenlarni ro'yxatlab olardi.
        if ($anketa === null || ! $this->qr->verify($anketa->qr_token, $anketa->reg_number, $signature)) {
            return response()->json([
                'valid' => false,
                'message' => 'Hujjat topilmadi yoki havola noto‘g‘ri.',
            ], 404);
        }

        $mahalla = DB::connection('master')->table('mahallas')
            ->where('id', $anketa->mahalla_id)->first(['name_lat', 'name_cyr']);

        $district = DB::connection('master')->table('districts')
            ->where('id', $anketa->district_id)->first(['name_lat', 'name_cyr']);

        return response()->json([
            'valid' => true,
            'document' => [
                'reg_number' => $anketa->reg_number,
                'filled_at' => $anketa->filled_at,
                'form_version' => $anketa->form_version,
                'age_group' => $anketa->age_group,
                // Toifa BELGISI — yashil/sariq. Bu shaxsiy ma'lumot emas:
                // u konkret odamga bog'lanmaydi, chunki ism ko'rsatilmaydi.
                'category' => $anketa->category,
                'status' => $anketa->status,
                'mahalla' => $mahalla->name_lat ?? null,
                'mahalla_cyr' => $mahalla->name_cyr ?? null,
                'district' => $district->name_lat ?? null,
                'district_cyr' => $district->name_cyr ?? null,
                // Kim to'ldirgan — LAVOZIM, ism EMAS.
                'filled_by_position' => $this->positionOf($anketa),
            ],
            'signatures' => $this->signatureChain($anketa),
        ]);
    }

    /**
     * To'ldirgan xodimning LAVOZIMI.
     *
     * Ism ataylab berilmaydi: hujjatni skanerlagan har kim uni ko'rardi
     * va bu MFY faoli uchun xavfsizlik masalasi bo'lishi mumkin
     * (masalan nizoli oila bilan ishlaganda).
     */
    private function positionOf(Anketa $anketa): ?string
    {
        if ($anketa->created_by === null) {
            return null;
        }

        return DB::connection('ayollar')->table('staff')
            ->where('user_id', $anketa->created_by)
            ->value('position');
    }

    /**
     * MFY balansining imzolar zanjiri.
     *
     * Anketa qaysi balansga tushganini davri bo'yicha topamiz. Imzolar
     * hujjat haqiqiyligining asosiy dalili: 6 ta imzo qo'yilgan balans
     * — tasdiqlangan hisobot.
     *
     * @return array<int, array<string, mixed>>
     */
    private function signatureChain(Anketa $anketa): array
    {
        if ($anketa->filled_at === null) {
            return [];
        }

        $balanceId = DB::connection('ayollar')->table('mahalla_balances')
            ->where('mahalla_id', $anketa->mahalla_id)
            ->where('period_year', $anketa->filled_at->year)
            ->where('period_month', $anketa->filled_at->month)
            ->value('id');

        if ($balanceId === null) {
            return [];
        }

        return BalanceSignature::query()
            ->where('balance_type', 'mahalla')
            ->where('balance_id', $balanceId)
            ->orderByRaw("array_position(?::text[], org_code)", ['{'.implode(',', BalanceSignature::MAHALLA_ORGS).'}'])
            ->get(['org_code', 'status', 'signed_at'])
            ->all();
    }
}
