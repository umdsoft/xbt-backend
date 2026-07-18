<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Middleware;

use App\Domains\Hr\Support\HrAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HR ruxsat middleware'i — xbt'dagi `can:permission` route middleware o'rnini
 * bosadi. Joriy HR aktyorining ruxsatini tekshiradi (super-admin bypass
 * Gate::before orqali).
 *
 * Ishlatilishi: ->middleware('hr.can:audit.view')
 */
class HrPermission
{
    public function __construct(private readonly HrAccess $access)
    {
    }

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $this->access->can($permission)) {
            abort(403, 'Бу амал учун рухсат йўқ.');
        }

        return $next($request);
    }
}
