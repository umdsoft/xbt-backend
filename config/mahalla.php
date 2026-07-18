<?php

declare(strict_types=1);

return [
    /*
     * Geofence: rasm honadon koordinatasidan shu radius (metr) ichida bo'lsa "ok".
     * Bundan tashqarida -> geofence_ok=false, AI'ga flag signal.
     */
    'geofence_radius_m' => (int) env('MAHALLA_GEOFENCE_RADIUS_M', 75),

    /*
     * GPS aniqligi shu qiymatdan (metr) yomon bo'lsa, ogohlantirish (rad etmaydi).
     */
    'gps_accuracy_warn_m' => (int) env('MAHALLA_GPS_ACCURACY_WARN_M', 50),

    /*
     * On-site ("aynan borgan holda") uchun GPS aniqligi shu qiymatdan yomon bo'lsa,
     * geofence_ok=false (masofa 75m ichida bo'lsa ham). Foydalanuvchi tanlovi: 100m.
     */
    'gps_accuracy_max_m' => (int) env('MAHALLA_GPS_ACCURACY_MAX_M', 100),

    /*
     * Rasm saqlash diski (config/filesystems.php). Maxfiy (local/private) —
     * rasmlar faqat vakolatli route orqali ko'riladi. Prod: s3/object storage.
     */
    'photos_disk' => env('MAHALLA_PHOTOS_DISK', 'local'),

    'ai' => [
        /*
         * Tahlil provayderi. 'claude' -> Anthropic Vision API. Kalit bo'lmasa,
         * kuzatuv "masul hodim tekshiruvi" (flagged) holatiga o'tadi — pipeline
         * baribir yakunlanadi (local test uchun ham qulay).
         */
        'driver' => env('MAHALLA_AI_DRIVER', 'claude'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('MAHALLA_AI_MODEL', 'claude-sonnet-5'), // vision-capable
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'max_tokens' => (int) env('MAHALLA_AI_MAX_TOKENS', 1500),
        'timeout' => (int) env('MAHALLA_AI_TIMEOUT', 60),

        /*
         * QUEUE ROBUSTLIGI (ko'p bir vaqtli so'rov uchun):
         *  - queue: AI tahlili alohida navbatda (yuklashni bloklamaydi)
         *  - rpm: daqiqadagi maksimal API so'rovi (rate-limit; oshsa job kechiktiriladi)
         *  - concurrency: bir vaqtda ishlaydigan maksimal AI chaqiruvi (Redis funnel)
         *  - max_attempts + backoff: 429/5xx da eksponensial qayta urinish
         */
        'queue' => env('MAHALLA_AI_QUEUE', 'ai'),
        'rpm' => (int) env('MAHALLA_AI_RPM', 50),
        'concurrency' => (int) env('MAHALLA_AI_CONCURRENCY', 8),
        'max_attempts' => (int) env('MAHALLA_AI_MAX_ATTEMPTS', 5),

        /*
         * Auto-tasdiq: AI shu ishonch (0..1) dan yuqori VA aniq O'ZGARISH topsa,
         * o'zi tasdiqlaydi. Aks holda (ikkilangan yoki o'zgarishsiz) -> masul hodim.
         */
        'auto_confirm_min_confidence' => (float) env('MAHALLA_AI_MIN_CONFIDENCE', 0.75),
    ],
];
