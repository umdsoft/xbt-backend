<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Middleware;

use App\Domains\Advisor\Support\AdvisorAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * advisor domeni gvardiyasi: foydalanuvchi 'advisor' tizimida (biror rol bilan)
 * ekanini tekshiradi. Rolsiz (advisor bo'lmagan) -> 403.
 * Auth-siz so'rov `auth:sanctum` tomonidan avval 401 bilan to'xtatiladi.
 * Ishlatilishi: ->middleware(['auth:sanctum', 'advisor']) (mahalla naqshi).
 */
class EnsureAdvisor
{
    public function __construct(private readonly AdvisorAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->access->isAdvisor($user)) {
            abort(403, 'Бу тизимга рухсат йўқ.');
        }

        return $next($request);
    }
}
