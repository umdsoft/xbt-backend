<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Middleware;

use App\Domains\Ayollar\Support\AyollarAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `ayollar` domeni gvardiyasi: foydalanuvchi shu tizimda (biror rol bilan)
 * ekanini tekshiradi. Rolsiz -> 403. Auth-siz -> 401 (auth:sanctum bergan).
 *
 * Ikki javob kodi ATAYLAB farqlanadi: 401 «kim ekaningni bilmayman» (SPA
 * login sahifasiga yuboradi), 403 «kimligingni bilaman, lekin bu tizimga
 * kirolmaysan» (SPA «ruxsat yo'q» sahifasini ko'rsatadi). Ikkalasi 401
 * qilinsa, boshqa modul foydalanuvchisi cheksiz login siklida qolardi.
 */
class EnsureAyollar
{
    public function __construct(private readonly AyollarAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->access->isAyollar($user)) {
            abort(403, 'Bu tizimga ruxsat yo‘q.');
        }

        return $next($request);
    }
}
