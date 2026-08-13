<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Middleware;

use App\Domains\Qurilish\Support\QurilishAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * qurilish domeni gvardiyasi: foydalanuvchi 'qurilish' tizimida (biror rol bilan)
 * ekanini tekshiradi. Rolsiz -> 403. Auth-siz -> 401 (auth:sanctum). advisor naqshi.
 * Ishlatilishi: ->middleware(['auth:sanctum', 'qurilish']).
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

        return $next($request);
    }
}
