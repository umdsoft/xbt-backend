<?php

declare(strict_types=1);

/*
 * CORS — Vue frontend'lar (xbt, mahalla, ...) platform API'ga cookie bilan murojaat qiladi.
 * supports_credentials=true + aniq origin'lar (wildcard EMAS — credentials bilan mumkin emas).
 */
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    // Env'dan vergul bilan ajratilgan origin'lar (dev: Vite serverlar; prod: subdomenlar)
    'allowed_origins' => array_filter(explode(',', (string) env(
        'FRONTEND_ORIGINS',
        'http://localhost:5173,http://localhost:5174,http://127.0.0.1:5173,http://127.0.0.1:5174'
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
