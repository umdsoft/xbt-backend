<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Http\Middleware;

use App\Domains\Murojaat\Support\MurojaatAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * murojaat domeni gvardiyasi: foydalanuvchi 'murojaat' tizimida (biror rol bilan)
 * ekanini tekshiradi. Rolsiz -> 403. Auth-siz -> 401 (auth:sanctum). advisor naqshi.
 */
class EnsureMurojaat
{
    public function __construct(private readonly MurojaatAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->access->isMurojaat($user)) {
            abort(403, 'Бу тизимга рухсат йўқ.');
        }

        return $next($request);
    }
}
