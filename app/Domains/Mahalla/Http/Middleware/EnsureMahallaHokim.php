<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Middleware;

use App\Domains\Mahalla\Support\MahallaAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ҳоким ёрдамчиси бўлими гвардияси.
 *
 * `admin` ham kiradi (tizimni sozlash uchun). `viloyat`/`rais` KIRMAYDI —
 * mikroloyiha boshqarish hokim yordamchisining vazifasi.
 */
class EnsureMahallaHokim
{
    public function __construct(private readonly MahallaAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $role = $user === null ? null : $this->access->roleFor($user);

        if (! in_array($role, ['admin', 'hokim-yordamchisi'], true)) {
            abort(403, 'Бу бўлим ҳоким ёрдамчиси учун.');
        }

        return $next($request);
    }
}
