<?php

declare(strict_types=1);

return [
    /*
     * Ko'rsatiladigan vaqt mintaqasi. Ilova UTC da ishlaydi; "chorak" va "bugun"
     * chegaralari MAHALLIY vaqt bo'yicha olinishi shart (mahalla naqshi).
     */
    'timezone' => env('ADVISOR_TIMEZONE', 'Asia/Tashkent'),

    /*
     * Geografiya manbasi — tumanlar markaziy master.districts'dan (Xorazm 13).
     * Yangi tuman jadvali YARATILMAYDI (spec §11, §15).
     */
    'districts_connection' => 'master',

    /*
     * Fayl saqlash diski (config/filesystems.php). Maxfiy (local/private) —
     * hisobot dalillari faqat vakolatli route orqali ochiladi. Prod: s3.
     * (Topshiriqlar/hisobotlar moduli keyingi bosqichda ishlatadi.)
     */
    'files_disk' => env('ADVISOR_FILES_DISK', 'local'),

    /*
     * Boshlang'ich (default) rol — yangi maslahatchi biriktirilganда.
     * To'liq rol ro'yxati: App\Domains\Advisor\Support\AdvisorAccess::ROLES.
     */
    'default_role' => env('ADVISOR_DEFAULT_ROLE', 'advisor_tuman'),
];
