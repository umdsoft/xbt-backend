<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Middleware;

use App\Domains\Mahalla\Support\MahallaAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * mahalla domeni ADMIN gvardiyasi: faqat super-admin (`admin` roli) boshqaruv
 * API'siga kira oladi. Operatsion (`deputat`) va boshqalar -> 403.
 * Ishlatilishi: ->middleware('mahalla.admin') ('system.access:mahalla' dan keyin).
 */
class EnsureMahallaAdmin
{
    public function __construct(private readonly MahallaAccess $access)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->access->roleFor($user) !== 'admin') {
            abort(403, 'Бу амал фақат администратор учун.');
        }

        return $next($request);
    }
}
