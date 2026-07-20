<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Middleware;

use App\Domains\Mahalla\Support\MahallaAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Маҳалла раиси бўлими гвардияси.
 *
 * `admin` ham kiradi — u tizimni sozlash va tekshirish uchun har bo'limga
 * kira olishi kerak. `viloyat` esa KIRMAYDI: u faqat ko'rish roli, kadastr
 * tuzatish esa yozish amali. Ko'rish va yozish huquqini bir joyga
 * qo'shish — avvalgi auditda topilgan xato sinfi.
 */
class EnsureMahallaRais
{
    public function __construct(private readonly MahallaAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $role = $user === null ? null : $this->access->roleFor($user);

        if (! in_array($role, ['admin', 'rais'], true)) {
            abort(403, 'Бу бўлим маҳалла раиси учун.');
        }

        return $next($request);
    }
}
