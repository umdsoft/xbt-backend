<?php

declare(strict_types=1);

return [
    /*
     * Ko'rsatiladigan vaqt mintaqasi. "Bugun"/"kun_otgan" chegaralari MAHALLIY
     * vaqt bo'yicha olinishi shart (advisor naqshi).
     */
    'timezone' => env('MUROJAAT_TIMEZONE', 'Asia/Tashkent'),

    /*
     * Geografiya manbasi — tumanlar markaziy master.districts'dan (Xorazm 13).
     */
    'districts_connection' => 'master',

    /*
     * Kechikkan (muddati o'tgan) chegarasi — javob berilmagan murojaat necha kundan
     * so'ng "kechikkan" hisoblanadi (HTMLdagi qoida: 15 kun).
     */
    'overdue_days' => (int) env('MUROJAAT_OVERDUE_DAYS', 15),

    /*
     * KPI reyting og'irliklari (PF-51, 3(g)-band). Yig'indi = 1.00.
     */
    'kpi_weights' => [
        'hal' => 0.35,
        'muddat' => 0.30,
        'qayta' => 0.20,
        'takror' => 0.15,
    ],

    /*
     * Boshlang'ich (default) rol — yangi foydalanuvchi biriktirilganда.
     */
    'default_role' => env('MUROJAAT_DEFAULT_ROLE', 'murojaat_viewer'),
];
