<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Middleware;

use App\Domains\Yoshlar\Support\YoshlarAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * yoshlar domeni gvardiyasi: foydalanuvchi 'yoshlar' tizimida (biror rol bilan)
 * ekanini tekshiradi. Rolsiz -> 403. Auth-siz -> 401 (auth:sanctum).
 */
class EnsureYoshlar
{
    public function __construct(private readonly YoshlarAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->access->isYoshlar($user)) {
            abort(403, 'Bu tizimga ruxsat yo‘q.');
        }

        return $next($request);
    }
}
