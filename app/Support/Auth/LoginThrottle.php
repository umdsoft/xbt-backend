<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * KIRISH URINISHLARI CHEKLOVI — HISOB BO'YICHA, IP BO'YICHA EMAS.
 *
 * NEGA O'ZGARTIRILDI.
 *
 * Avval route'da `throttle:5,1` turardi va u FAQAT IP bo'yicha
 * sanardi. Xorazmdagi MFY faollari hokimiyat va mahalla idoralaridan
 * kiradi — o'nlab odam bitta tashqi IP ortida. Natijada bir faolning
 * uchta xato urinishi qo'shnisining to'g'ri parolini ham bloklardi va
 * ekranda inglizcha «Too Many Attempts.» chiqardi. Faol buni «hisobim
 * ishlamayapti» deb tushunardi.
 *
 * Jurnal buni tasdiqladi: `/api/login` da 88 ta 429, ularning 25 tasi
 * BITTA IPdan (198.163.193.131) va aynan ish boshlanadigan
 * daqiqalarda to'plangan.
 *
 * ENDI UCH QATLAM — har biri boshqa hujumga qarshi:
 *
 *   1. HISOB + IP (5/daqiqa) — asosiy himoya. Bitta hisobga parol
 *      terish shu yerda to'xtaydi va u FAQAT o'sha hisobga tegadi.
 *   2. HISOB (10/daqiqa, hamma IPdan) — bir hisobga ko'p joydan
 *      hujum qilinsa ham chegara bor.
 *   3. IP (40/daqiqa) — login nomlarini sanab chiqishga qarshi
 *      shift. Bitta idora uchun yetarlicha keng: 40 xodim bir
 *      daqiqada kira oladi, avtomat sanash esa bir necha soniyada
 *      shu chegaraga uriladi.
 *
 * Ya'ni brute-force himoyasi KUCHAYDI (avval hisob bo'yicha chegara
 * umuman yo'q edi — hujumchi IP almashtirib cheksiz urinardi), idora
 * esa bloklanmaydi.
 */
final class LoginThrottle
{
    public const NAME = 'login';

    private const PER_ACCOUNT_IP = 5;

    private const PER_ACCOUNT = 10;

    private const PER_IP = 40;

    public static function register(): void
    {
        RateLimiter::for(self::NAME, fn (Request $request) => [
            Limit::perMinute(self::PER_ACCOUNT_IP)
                ->by(self::accountKey($request).'|'.$request->ip())
                ->response(self::response(...)),
            Limit::perMinute(self::PER_ACCOUNT)
                ->by(self::accountKey($request))
                ->response(self::response(...)),
            Limit::perMinute(self::PER_IP)
                ->by('ip|'.$request->ip())
                ->response(self::response(...)),
        ]);
    }

    /**
     * Kalit — login nomi, normallashtirilgan.
     *
     * `Munis_Xorazmiy` va `munis_xorazmiy` bitta hisob: aks holda
     * harf registrini o'zgartirib chegarani aylanib o'tish mumkin
     * bo'lardi.
     */
    private static function accountKey(Request $request): string
    {
        $login = mb_strtolower(trim((string) $request->input('login')));

        return 'acc|'.($login === '' ? 'bosh' : $login);
    }

    /**
     * 429 javobi — O'QILADIGAN MATN BILAN.
     *
     * Laravel'ning o'z matni «Too Many Attempts.» — inglizcha va
     * vaqtinchalik ekanini aytmaydi. MFY faoli uchun bu «hisobim
     * buzilgan» degani edi. Endi javob nechа soniya kutish kerakligini
     * aytadi.
     */
    private static function response(Request $request, array $headers): JsonResponse
    {
        $seconds = (int) ($headers['Retry-After'] ?? 60);

        return response()->json([
            'message' => "Кўп марта уриниб кўрилди. {$seconds} сония кутинг ва қайта киринг. "
                .'Парол эсдан чиққан бўлса, туман маъмуридан янгисини сўранг.',
            'retry_after' => $seconds,
        ], 429, $headers);
    }
}
