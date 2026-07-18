<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Markaziy identifikatsiya: foydalanuvchi ushbu tizimga (code) ruxsatlimi?
 * Ishlatilishi: ->middleware('system.access:mahalla')
 */
class EnsureSystemAccess
{
    public function handle(Request $request, Closure $next, string $systemCode): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->canAccessSystem($systemCode)) {
            abort(403, 'Бу тизимга рухсат йўқ.');
        }

        return $next($request);
    }
}
