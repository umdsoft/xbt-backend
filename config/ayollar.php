<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | PII shifrlash kaliti
    |--------------------------------------------------------------------------
    |
    | Maxsus toifadagi shaxsiy ma'lumot (JShShIR, pasport, telefon, V bo'lim
    | javoblari) shu kalit bilan AES-256-GCM da shifrlanadi.
    |
    | Kalit `.env` DA EMAS, alohida faylda bo'lishi kerak — repodan tashqarida,
    | 0400 huquqi bilan, faqat php-fpm foydalanuvchisiga o'qishga ochiq:
    |
    |     sudo install -o www-data -g www-data -m 0400 /dev/null /etc/ayollar/pii.key
    |     openssl rand -base64 48 | sudo tee /etc/ayollar/pii.key > /dev/null
    |     AYOLLAR_PII_KEY_PATH=/etc/ayollar/pii.key
    |
    | Belgilanmasa APP_KEY ishlatiladi — bu FAQAT lokal ishlab chiqish uchun.
    | Prodda `ayollar:check-key` buyrug'i ogohlantiradi.
    |
    | DIQQAT: kalit almashtirilsa, mavjud shifrmatnlar O'QILMAY QOLADI.
    | Almashtirish uchun `ayollar:rotate-pii-key` kerak (hali yozilmagan).
    |
    */
    'pii_key_path' => env('AYOLLAR_PII_KEY_PATH', ''),

    /*
    |--------------------------------------------------------------------------
    | QR
    |--------------------------------------------------------------------------
    |
    | Ochiq tekshiruv sahifasining manzili. QR ichida FAQAT token va HMAC
    | bo'ladi — JShShIR, ism yoki telefon HECH QACHON (promt §7).
    |
    */
    'qr' => [
        'base_url' => env('AYOLLAR_QR_BASE_URL', 'https://ayollar.digital-xorazm.uz'),
        'token_length' => 6,
        'hmac_length' => 32,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ro'yxat raqami
    |--------------------------------------------------------------------------
    |
    | XOR-08-0142-2026-000731
    |  │   │   │     │     └── MFY ichidagi ketma-ket raqam
    |  │   │   │     └──────── yil
    |  │   │   └────────────── MFY kodi (4 xona)
    |  │   └────────────────── tuman kodi (01–13)
    |  └────────────────────── viloyat prefiksi
    |
    */
    'reg_number' => [
        'region_prefix' => env('AYOLLAR_REGION_PREFIX', 'XOR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline paket
    |--------------------------------------------------------------------------
    |
    | Planshet navbatdan bir martada yuboradigan maksimal yozuv soni.
    | 100 dan oshsa so'rov tanasi kattalashib, sekin 3G'da uzilib qolardi.
    |
    */
    'batch_limit' => 100,

    /*
    |--------------------------------------------------------------------------
    | Kunlik marshrut normasi
    |--------------------------------------------------------------------------
    |
    | Planshet bosh ekranidagi «bugungi reja N xonadon» va marshrut
    | uzunligi. Norma KADASTRDAN kelmaydi — u ish tashkiloti masalasi,
    | shuning uchun sozlamada turadi.
    |
    | 12: bitta xonadonda o'rtacha 2-3 ayol, har biriga 10-15 daqiqa;
    | ish kunining yarmi yurishga ketadi. Bu son ko'paytirilsa,
    | anketa sifati tushadi — faol shoshib to'ldiradi.
    |
    */
    'daily_household_norm' => (int) env('AYOLLAR_DAILY_NORM', 12),

];
