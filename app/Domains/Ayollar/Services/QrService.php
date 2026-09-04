<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use App\Domains\Ayollar\Models\Anketa;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Anketaning QR havolasi va uni tekshirish.
 *
 *     https://ayollar.digital-xorazm.uz/a/{qr_token}?v={qr_hmac}
 *
 * QR ICHIDA JShShIR, ISM YOKI TELEFON YO'Q (promt §7 va §14). QR — ochiq
 * hujjatda bosiladi va uni skanerlagan HAR KIM havolani ochadi. Agar ichida
 * shaxsiy ma'lumot bo'lsa, hujjat suratini olgan odam uni ham olardi.
 *
 * `qr_token` — tasodifiy 6 belgi (base32). Taxmin qilib bo'lmaydi.
 * `qr_hmac`  — token va ro'yxat raqamining imzosi. Tokenni topgan odam
 *              qo'shni tokenlarni sinab ko'rmasin: imzosiz havola OCHILMAYDI.
 */
class QrService
{
    /** Base32 (Crockford) — chalkashadigan belgilar (I, L, O, U) yo'q. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Tasodifiy, unikal token. */
    public function generateToken(): string
    {
        $length = (int) config('ayollar.qr.token_length', 6);

        do {
            $token = '';
            for ($i = 0; $i < $length; $i++) {
                $token .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Anketa::query()->withTrashed()->where('qr_token', $token)->exists());

        return $token;
    }

    /**
     * Havola imzosi.
     *
     * `APP_KEY` ga bog'langan: kalit almashsa eski QR'lar ishlamay qoladi.
     * Bu ATAYLAB — QR bosilgan hujjatning amal qilish muddati kalit
     * muddatidan uzoq bo'lmasligi kerak.
     */
    public function sign(string $token, string $regNumber): string
    {
        $length = (int) config('ayollar.qr.hmac_length', 32);

        return substr(hash_hmac('sha256', $token.'|'.$regNumber, (string) config('app.key')), 0, $length);
    }

    /**
     * Imzo to'g'rimi.
     *
     * `hash_equals` — vaqt bo'yicha hujumga qarshi. Oddiy `===` bilan
     * taqqoslash imzoni belgi-belgi topish imkonini berardi.
     */
    public function verify(string $token, string $regNumber, string $hmac): bool
    {
        return hash_equals($this->sign($token, $regNumber), $hmac);
    }

    public function url(Anketa $anketa): string
    {
        $base = rtrim((string) config('ayollar.qr.base_url'), '/');

        return "{$base}/a/{$anketa->qr_token}?v={$anketa->qr_hmac}";
    }

    /**
     * QR kodini SVG sifatida qaytaradi.
     *
     * SVG, PNG EMAS: hujjat har xil o'lchamda bosiladi (ekranda 120 px,
     * PDF'da 25x25 mm) va rastr kichik o'lchamda modul chegaralarini
     * xiralashtiradi — skaner o'qimay qoladi. SVG har o'lchamda aniq.
     *
     * Tuzatish darajasi M (~15%): hujjat bukiladi va muhr bosiladi,
     * shuning uchun L yetarli emas; H esa matritsani kattalashtirib,
     * 25 mm da modulni juda mayda qilardi.
     */
    /**
     * QR modullarining MATRITSASI — PDF uchun.
     *
     * NEGA RASM EMAS: `dompdf` inline SVG ni to'liq qo'llab-quvvatlamaydi,
     * PNG uchun esa `imagick` yoki GD kerak — ikkalasi ham prod serverda
     * bo'lishi kafolatlanmagan. Matritsa esa oddiy jadval bo'lib
     * chiziladi va HAR QANDAY muhitda bir xil chiqadi.
     *
     * @return array<int, array<int, bool>> [qator][ustun] = qora modulmi
     */
    public function matrix(Anketa $anketa): array
    {
        $code = Encoder::encode(
            $this->url($anketa),
            ErrorCorrectionLevel::M(),
            Encoder::DEFAULT_BYTE_MODE_ECODING,
        );

        $bytes = $code->getMatrix();
        $rows = [];

        for ($y = 0; $y < $bytes->getHeight(); $y++) {
            $row = [];

            for ($x = 0; $x < $bytes->getWidth(); $x++) {
                $row[] = $bytes->get($x, $y) === 1;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * QR ni TO'RTBURCHAKLAR ro'yxatiga aylantiradi (run-length).
     *
     * NEGA MATRITSA EMAS: 37x37 = 1369 katak. dompdf har katak uchun
     * obyekt yaratadi va bitta PDF 128 MB xotira limitini oshirib
     * yuboradi — bu aynan sodir bo'ldi. Qatordagi ketma-ket qora
     * modullarni bitta to'rtburchakka birlashtirish element sonini
     * ~5 barobar kamaytiradi.
     *
     * O'lchamlar MODUL birligida: chaqiruvchi ularni mm ga o'giradi.
     *
     * @return array{size: int, runs: array<int, array{x: int, y: int, w: int}>}
     */
    public function runs(Anketa $anketa): array
    {
        $matrix = $this->matrix($anketa);
        $runs = [];

        foreach ($matrix as $y => $row) {
            $start = null;

            foreach ($row as $x => $on) {
                if ($on && $start === null) {
                    $start = $x;
                } elseif (! $on && $start !== null) {
                    $runs[] = ['x' => $start, 'y' => $y, 'w' => $x - $start];
                    $start = null;
                }
            }

            // Qator oxirigacha davom etgan yugurish.
            if ($start !== null) {
                $runs[] = ['x' => $start, 'y' => $y, 'w' => count($row) - $start];
            }
        }

        return ['size' => count($matrix), 'runs' => $runs];
    }

    public function svg(Anketa $anketa, int $size = 200): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle($size, margin: 1),
            new SvgImageBackEnd,
        ));

        return $writer->writeString(
            $this->url($anketa),
            Encoder::DEFAULT_BYTE_MODE_ECODING,
            ErrorCorrectionLevel::M(),
        );
    }
}
