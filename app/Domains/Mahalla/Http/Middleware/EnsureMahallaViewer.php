<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Middleware;

use App\Domains\Mahalla\Support\MahallaAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rahbariyat dashboard'i gvardiyasi: `admin` va `viloyat` rollariga ruxsat.
 * `mahalla.admin` dan farqi — bu FAQAT KO'RISH bo'limi uchun; boshqaruv
 * endpointlari o'z gvardiyasida qoladi, shuning uchun viloyat roli u yerga
 * kira olmaydi.
 */
class EnsureMahallaViewer
{
    public function __construct(private readonly MahallaAccess $access)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! in_array($this->access->roleFor($user), MahallaAccess::VIEWER_ROLES, true)) {
            abort(403, 'Бу бўлим фақат раҳбарият учун.');
        }

        return $next($request);
    }
}
