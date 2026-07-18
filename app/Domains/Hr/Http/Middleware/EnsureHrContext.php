<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Middleware;

use App\Domains\Hr\Support\HrAccess;
use App\Domains\Hr\Support\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HR (KBT) domeni uchun joriy request tenant kontekstini o'rnatadi.
 *
 * Logika (xbt EnsureTenantAccess bilan bir xil):
 *  - Markaziy foydalanuvchidan HR profil aniqlanadi.
 *  - Super-admin / viloyat-admin — global rejim (barcha tenantlar);
 *    ?tenant=UUID orqali bitta tenantga o'tishi mumkin.
 *  - Boshqa rollar — faqat o'z hokimligi.
 *
 * MUHIM: bu middleware SubstituteBindings'dan OLDIN ishlashi shart — aks holda
 * route-model binding tenant bo'yicha scope qilinmay, IDOR yuzaga kelardi.
 */
class EnsureHrContext
{
    public function __construct(
        private readonly HrAccess $access,
        private readonly TenantContext $context,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $actor = $this->access->resolveFor($user);
        if ($actor === null) {
            abort(403, 'HR профили топилмади.');
        }

        $isCrossTenantAdmin = $actor->hasRole('super-admin') || $actor->hasRole('viloyat-admin');

        if ($isCrossTenantAdmin) {
            $requestedTenant = $request->query('tenant');
            if ($requestedTenant !== null && $requestedTenant !== '') {
                $this->context->set((string) $requestedTenant, isGlobal: false);
            } else {
                $this->context->set(null, isGlobal: true);
            }
        } else {
            $this->context->set($actor->hokimlik_id, isGlobal: false);
        }

        return $next($request);
    }
}
