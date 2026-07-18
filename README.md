<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Deploy (Production)

Bu backend — markaziy identifikatsiya (Sanctum SPA) + `mahalla` va `hr`/KBT domenlari uchun yagona Laravel API. Deploydan oldin `DEPLOY-AUDIT.md` ni o'qing.

> ⛔ **`migrate:fresh` / `migrate:rollback` — TAQIQ (bo'linadigan DB).** `down()` metodlari boshqa app jadvallarini o'chirib yuborishi mumkin. Prod'da **alohida bo'sh `xorazm` DB** ishlating va faqat oldinga (`migrate --force`) yuring.

### 1. Prod `.env`
`.env.example` ni nusxalab, `# [PROD]` bilan belgilangan barcha kalitlarni to'ldiring. Eng muhimlari:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://api.digital-xorazm.uz`
- `APP_KEY=` → `php artisan key:generate` (bo'sh qolmasin)
- `DB_CONNECTION=pgsql` (sqlite emas), `DB_DATABASE=xorazm` (bo'linmagan, alohida), `DB_*` + `DB_SSLMODE`
- `DB_SEARCH_PATH` / `DB_AUTH_SEARCH_PATH` / `DB_MASTER_SEARCH_PATH` / `DB_MAHALLA_SEARCH_PATH` / `DB_HR_SEARCH_PATH`
- Sessiya qattiqlashtirish: `SESSION_DRIVER=database`, `SESSION_CONNECTION=auth`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=.digital-xorazm.uz`, `SESSION_COOKIE=xorazm_session`
- `SANCTUM_STATEFUL_DOMAINS` + `FRONTEND_ORIGINS` → real sub-domenlar (localhost EMAS)
- `ANTHROPIC_API_KEY` (bo'lmasa AI rasm tahlili "pending" qoladi), `MAHALLA_AI_MODEL`
- `ADMIN_SEED_PASSWORD` (super-admin boshlang'ich paroli), `QUEUE_CONNECTION`, `CACHE_STORE`, `LOG_LEVEL=warning`, `LOG_STACK=daily`

### 2. O'rnatish va migratsiya
```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate            # APP_KEY bo'sh bo'lsa

# PostgreSQL schema'larini yarating (aynan bir marta, DB yaratilgach):
#   CREATE SCHEMA IF NOT EXISTS auth;
#   CREATE SCHEMA IF NOT EXISTS master;
#   CREATE SCHEMA IF NOT EXISTS mahalla;
#   CREATE SCHEMA IF NOT EXISTS platform;
# (migratsiyalarda create_domain_schemas mavjud bo'lsa — avtomatik)

php artisan migrate --force         # FAQAT oldinga; migrate:fresh EMAS
php artisan db:seed --force         # systems + rollar/ruxsatlar + super-admin
php artisan storage:link
```

### 3. Cache (deploy oxirida)
```bash
php artisan config:cache
php artisan route:cache             # closure-siz route'lar sabab endi ishlaydi
php artisan view:cache
```
Kod/`.env` o'zgarsa qayta ishga tushiring yoki `config:clear` / `route:clear`.

### 4. Queue worker (AI rasmlar uchun MAJBURIY)
`AnalyzePhotoJob` async ishlaydi — worker bo'lmasa rasmlar doim `pending` qoladi. supervisor yoki systemd bilan doimiy ishlating:
```bash
php artisan queue:work --tries=3 --timeout=180
```

### 5. nginx → php-fpm + TLS
- TLS (HTTPS) terminatsiya nginx'da; `proxy_set_header X-Forwarded-Proto $scheme;` (va `X-Forwarded-For/Host/Port`) uzatilsin.
- Ilova `TrustProxies(at: '*')` bilan sozlangan (`bootstrap/app.php`) — shu tufayli HTTPS to'g'ri aniqlanadi (absolut rasm URL'lari + secure cookie).
- `sanctum/csrf-cookie`, `login`, `logout`, `api/*` yo'llari CORS'da (`config/cors.php`, `supports_credentials=true`).

### 6. Deploydan keyin tekshiruv
- `curl https://api.digital-xorazm.uz/api/me` (JSON header'siz ham) → **401 JSON** (500 emas).
- SPA login oqimi: `GET /sanctum/csrf-cookie` → `POST /api/login` (to'g'ri `Origin` bilan).
- 6-marta ketma-ket noto'g'ri login → **429** (rate-limit `throttle:5,1`).
- Rasm yuklab, queue worker ishlayotganini va tahlil `pending`dan chiqishini tekshiring.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
