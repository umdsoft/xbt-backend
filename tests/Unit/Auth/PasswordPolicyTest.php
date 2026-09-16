<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Support\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

/**
 * PAROL SIYOSATI — qoidaning O'ZI.
 *
 * Bu yerda bazaga ham, HTTP ga ham tegilmaydi: `reject()` sof
 * funksiya. Uning xatosi jimgina o'tib ketadigan turdan — zaif parol
 * qabul qilinsa, hech qanday xato chiqmaydi, faqat hisob himoyasiz
 * qoladi. Shuning uchun har qoida alohida tekshiriladi.
 */
class PasswordPolicyTest extends TestCase
{
    public function test_login_ichidagi_parol_rad_etiladi(): void
    {
        $this->assertNotNull(
            PasswordPolicy::reject('xazorasp_istiqlol1', 'xazorasp_istiqlol'),
            'Login butunlay parol ichida — bu birinchi taxmin qilinadigan variant.',
        );
    }

    public function test_mahalla_nomi_ichidagi_parol_rad_etiladi(): void
    {
        // `urgancht_adolat` -> `adolat`. Mahalla nomi ochiq ma'lumot va
        // hisob egasi bilan bog'langan, ya'ni taxmin qilish oson.
        $this->assertNotNull(PasswordPolicy::reject('Adolat2026', 'urgancht_adolat'));
    }

    public function test_qisqa_login_dumi_tekshirilmaydi(): void
    {
        // `a_bc` dagi `bc` — ikki belgi. Uni tekshirish tasodifiy mos
        // kelish tufayli mutlaqo yaroqli parolni rad etardi.
        $this->assertNull(PasswordPolicy::reject('Mustaqil2026', 'a_bc'));
    }

    public function test_oddiy_parollar_rad_etiladi(): void
    {
        foreach (['password', 'Parol12345', 'AYOLLAR123'] as $weak) {
            $this->assertNotNull(
                PasswordPolicy::reject($weak, 'shovot_qiyot'),
                "«{$weak}» ro'yxatda turishi kerak edi.",
            );
        }
    }

    public function test_yaxshi_parol_otadi(): void
    {
        $this->assertNull(PasswordPolicy::reject('Bahorgi7Shamol', 'shovot_qiyot'));
    }

    public function test_tekshiruv_katta_kichik_harfga_bogliq_emas(): void
    {
        // Login kichik harfda, parolda katta harf bilan yozilgan —
        // baribir bir xil so'z.
        $this->assertNotNull(PasswordPolicy::reject('ZIYOLILAR99', 'xazorasp_ziyolilar'));
    }

    public function test_loginsiz_ham_ishlaydi(): void
    {
        $this->assertNull(PasswordPolicy::reject('Bahorgi7Shamol', ''));
    }
}
