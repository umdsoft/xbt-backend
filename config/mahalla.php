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
     * Rasm saqlash diski (config/filesystems.php). Maxfiy (local/private) —
     * rasmlar faqat vakolatli route orqali ko'riladi. Prod: s3/object storage.
     */
    'photos_disk' => env('MAHALLA_PHOTOS_DISK', 'local'),

    'ai' => [
        /*
         * Tahlil provayderi. 'claude' -> Anthropic Vision API. Kalit bo'lmasa,
         * tahlil 'pending' holatda qoladi (yuklash baribir ishlaydi).
         */
        'driver' => env('MAHALLA_AI_DRIVER', 'claude'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('MAHALLA_AI_MODEL', 'claude-fable-5'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),

        /*
         * Auto-tasdiq chegaralari: shu shartlar bajarilsa AI o'zi tasdiqlaydi.
         */
        'auto_confirm_min_confidence' => (float) env('MAHALLA_AI_MIN_CONFIDENCE', 0.85),
    ],
];
