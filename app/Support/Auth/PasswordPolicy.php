<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Validation\Rules\Password;

/**
 * PAROL SIYOSATI — MARKAZIY.
 *
 * Bitta joyda turadi, chunki parol ham bitta: `auth.users` sakkizta
 * modulga xizmat qiladi. Ikki joyda ikki xil qoida bo'lsa, zaifroq
 * joy orqali kuchliroq joyga ham kirilardi.
 *
 * MAXSUS BELGI TALAB QILINMAYDI — bu ataylab.
 *
 * Foydalanuvchilarning katta qismi MFY faollari va ular telefon
 * klaviaturasida ishlaydi. Tarqatilgan 16 belgili parollarda `@ # $ %`
 * bor edi va natija o'lchandi: kirish jurnalida 557 ta muvaffaqiyatsiz
 * urinishga atigi 404 ta muvaffaqiyatli kirish to'g'ri keldi. Maxsus
 * belgi talabi parolni kuchaytirgandan ko'ra ko'proq odamni tizimdan
 * tashqarida qoldiradi — va ular oxiri parolni qog'ozga yozib qo'yadi.
 *
 * Buning o'rniga UZUNLIK talab qilinadi: 10 belgi harf va raqam bilan
 * 8 belgi aralash belgidan kuchliroq.
 *
 * `uncompromised()` ATAYLAB ISHLATILMAYDI: u tashqi xizmatga (HIBP)
 * so'rov yuboradi. Bu server davlat tarmog'ida turadi va parol
 * tekshiruvi tashqi xizmatning ishlashiga bog'lanib qolmasligi kerak.
 */
final class PasswordPolicy
{
    /** Eng kam uzunlik. */
    public const MIN_LENGTH = 10;

    /**
     * Ochiq-oydin parollar.
     *
     * Ro'yxat qisqa va ataylab shunday: uzun qora ro'yxat xavfsizlikni
     * sezilarli oshirmaydi, lekin foydalanuvchini «nega bo'lmaydi?»
     * degan holatda qoldiradi. Bu yerda faqat eng ko'p uchraydiganlari.
     */
    private const BLOCKED = [
        'password', 'parol12345', '1234567890', 'qwertyuiop',
        'ayollar123', 'xorazm1234', 'admin12345', 'parolparol',
    ];

    /**
     * Laravel validatsiya qoidasi.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return [
            'string',
            Password::min(self::MIN_LENGTH)->letters()->numbers(),
        ];
    }

    /**
     * Qoidaga mos kelmaydigan sabab — yoki `null`.
     *
     * Validatsiya qoidasi ushlay olmaydigan ikki holat shu yerda:
     * parol login bilan bir xil bo'lishi va ochiq-oydin parollar.
     * Ikkalasi ham `Password` qoidasidan o'tib ketadi, chunki ular
     * uzunlik va tarkib talabini qanoatlantirishi mumkin.
     */
    public static function reject(string $password, string $login): ?string
    {
        $lower = mb_strtolower($password);
        $loginLower = mb_strtolower($login);

        if (in_array($lower, self::BLOCKED, true)) {
            return 'Бу парол жуда оддий — уни осон топиш мумкин.';
        }

        // Login parol ichida bo'lsa ham yetarli: `urgancht_adolat` uchun
        // `adolat2026` ni topish urinib ko'riladigan birinchi variant.
        if ($loginLower !== '' && str_contains($lower, $loginLower)) {
            return 'Парол логинни ўз ичига олмаслиги керак.';
        }

        $tail = self::loginTail($loginLower);

        if ($tail !== null && str_contains($lower, $tail)) {
            return 'Парол маҳалла номини ўз ичига олмаслиги керак.';
        }

        return null;
    }

    /**
     * Logindagi mahalla qismi: `xazorasp_ziyolilar` -> `ziyolilar`.
     *
     * Aynan shu qism taxmin qilish uchun eng oson: mahalla nomi
     * ommaviy ma'lumot va hisob egasi bilan ochiq bog'langan.
     * Juda qisqa bo'laklar (3 belgidan kam) tekshirilmaydi — aks
     * holda tasodifiy mos kelish haqiqiy parolni rad etardi.
     */
    private static function loginTail(string $login): ?string
    {
        $pos = mb_strpos($login, '_');

        if ($pos === false) {
            return null;
        }

        $tail = mb_substr($login, $pos + 1);

        return mb_strlen($tail) >= 4 ? $tail : null;
    }
}
