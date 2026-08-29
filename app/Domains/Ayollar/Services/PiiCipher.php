<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use RuntimeException;
use SensitiveParameter;

/**
 * Maxsus toifadagi shaxsiy ma'lumot uchun maydon darajasida shifrlash.
 *
 * Bazada zo'ravonlik qurbonlari, odam savdosi qurbonlari, narkologiya hisobi
 * ma'lumotlari bo'ladi. Bazaning o'zi o'g'irlansa ham, bu maydonlar
 * o'qilmasligi kerak.
 *
 * NEGA LARAVEL `encrypted` CAST'I EMAS: u APP_KEY'ga bog'langan, APP_KEY esa
 * `.env` da — ya'ni ilova serveriga kirgan har kim uni oladi. Bu yerda kalit
 * ALOHIDA faylda (`AYOLLAR_PII_KEY_PATH`, 0400, repodan tashqarida): ilova
 * kodini o'qish PII'ni ochish uchun yetarli emas.
 *
 * MOSLASHTIRISH: promt KMS talab qiladi. Platforma LAN'dagi Ubuntu serverda
 * ishlaydi, KMS yo'q. Kalit manbai `keyMaterial()` da IZOLYATSIYA qilingan —
 * KMS paydo bo'lganda faqat shu metod o'zgaradi, shifrmatn formati emas.
 *
 * FORMAT: `v1:` + base64(nonce[12] ‖ tag[16] ‖ ciphertext)
 * Prefiks versiyalash uchun: algoritm almashsa, eski yozuvlar o'qilaveradi.
 */
class PiiCipher
{
    private const CIPHER = 'aes-256-gcm';

    private const VERSION = 'v1';

    private const NONCE_LEN = 12;

    private const TAG_LEN = 16;

    private ?string $key = null;

    private ?string $hashKey = null;

    public function encrypt(#[SensitiveParameter] ?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';

        $cipher = openssl_encrypt(
            $plain,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LEN,
        );

        if ($cipher === false) {
            throw new RuntimeException('PII shifrlanmadi.');
        }

        return self::VERSION.':'.base64_encode($nonce.$tag.$cipher);
    }

    /**
     * Shifrni ochadi.
     *
     * Buzuq yoki o'zgartirilgan shifrmatn — `null`, ISTISNO EMAS. Sabab:
     * bitta buzuq yozuv butun ro'yxat sahifasini 500 xatoga aylantirmasligi
     * kerak. GCM teg tekshiruvi o'zgartirishni allaqachon ushlaydi.
     */
    public function decrypt(#[SensitiveParameter] ?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        if (! str_starts_with($payload, self::VERSION.':')) {
            return null;
        }

        $raw = base64_decode(substr($payload, strlen(self::VERSION) + 1), true);

        if ($raw === false || strlen($raw) <= self::NONCE_LEN + self::TAG_LEN) {
            return null;
        }

        $nonce = substr($raw, 0, self::NONCE_LEN);
        $tag = substr($raw, self::NONCE_LEN, self::TAG_LEN);
        $cipher = substr($raw, self::NONCE_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($cipher, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $nonce, $tag);

        return $plain === false ? null : $plain;
    }

    /**
     * Qidiriladigan determinstik hash (dublikat tekshiruvi uchun).
     *
     * NEGA KERAK: GCM har safar boshqa nonce ishlatadi, shuning uchun bir xil
     * JShShIR ikki xil shifrmatn beradi va `WHERE pinfl_encrypted = ?` HECH
     * QACHON topmaydi. Dublikat esa viloyat bo'yicha ushlanishi SHART.
     *
     * Kalit shifrlash kalitidan AJRATILGAN (HKDF): hash sizib chiqsa,
     * shifrlash kaliti xavf ostida qolmaydi.
     */
    public function hash(#[SensitiveParameter] string $plain): string
    {
        $normalized = preg_replace('/\s+/u', '', $plain) ?? $plain;

        return hash_hmac('sha256', $normalized, $this->hashKey());
    }

    /**
     * Maskalash — ro'yxatlarda faqat oxirgi 4 xona ko'rinadi.
     *
     * Bu KO'RINISH qatlami emas, xavfsizlik chegarasi: to'liq qiymat API
     * javobiga umuman TUSHMAYDI. Frontendda maskalash yetarli bo'lmasdi —
     * javobni brauzer konsolida ko'rish mumkin.
     */
    public function mask(#[SensitiveParameter] ?string $plain, int $visible = 4): string
    {
        if ($plain === null || $plain === '') {
            return '—';
        }

        $tail = mb_substr($plain, -$visible);
        $hidden = max(0, mb_strlen($plain) - $visible);

        return str_repeat('•', $hidden).$tail;
    }

    private function key(): string
    {
        return $this->key ??= $this->deriveKey('ayollar-pii-encryption-v1');
    }

    private function hashKey(): string
    {
        return $this->hashKey ??= $this->deriveKey('ayollar-pii-lookup-v1');
    }

    /**
     * Kalitni ajratib chiqaradi.
     *
     * HKDF: bitta manba materialdan ikki mustaqil kalit (shifrlash va
     * qidiruv). Bir xil kalitni ikkala maqsadda ishlatish — kriptografik
     * xato: hash sizib chiqsa, u shifrlash kalitiga ishora berardi.
     */
    private function deriveKey(string $info): string
    {
        return hash_hkdf('sha256', $this->keyMaterial(), 32, $info);
    }

    /**
     * Manba kalit materiali.
     *
     * TARTIB MUHIM:
     *   1. `AYOLLAR_PII_KEY_PATH` fayli — ISHLAB CHIQARISHDA shu ishlatiladi.
     *   2. APP_KEY — faqat lokal/test uchun zaxira, aks holda dasturchi har
     *      safar kalit fayli yaratishga majbur bo'lardi va bu ish tartibini
     *      buzardi.
     *
     * Prodda 1-variant bo'lmasa ogohlantirish yozilishi kerak — buni
     * `AyollarServiceProvider` boot'ida tekshiramiz.
     */
    private function keyMaterial(): string
    {
        $path = (string) config('ayollar.pii_key_path', '');

        if ($path !== '' && is_readable($path)) {
            $material = trim((string) file_get_contents($path));

            if ($material !== '') {
                return $material;
            }
        }

        $appKey = (string) config('app.key');

        if ($appKey === '') {
            throw new RuntimeException('PII kaliti ham, APP_KEY ham yo‘q.');
        }

        return $appKey;
    }

    /** Kalit alohida fayldan olinganmi (prod tayyorligi tekshiruvi uchun). */
    public function usesDedicatedKey(): bool
    {
        $path = (string) config('ayollar.pii_key_path', '');

        return $path !== '' && is_readable($path);
    }
}
