<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Middleware;

use App\Domains\Qurilish\Support\QurilishAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * qurilish domeni gvardiyasi: foydalanuvchi 'qurilish' tizimida (biror rol bilan)
 * ekanini tekshiradi. Rolsiz -> 403. Auth-siz -> 401 (auth:sanctum). advisor naqshi.
 * Ishlatilishi: ->middleware(['auth:sanctum', 'qurilish']).
 *
 * Shu yerda lokal ham `oz` ga o'tkaziladi: qurilish platformasi to'liq kirillda
 * va validatsiya xatosi inglizcha chiqib qolmasin. Lokal FAQAT shu marshrut
 * guruhida almashadi — boshqa domenlar javobiga ta'sir qilmaydi.
 */
class EnsureQurilish
{
    public function __construct(private readonly QurilishAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->access->isQurilish($user)) {
            abort(403, 'Бу тизимга рухсат йўқ.');
        }

        App::setLocale('oz');

        return $next($request);
    }
}
