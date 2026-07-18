<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin / viloyat-admin uchun tenant switcher.
 *
 * SPA API'da tanlash har requestda `?tenant=UUID` query param orqali uzatiladi;
 * bu endpoint faqat tanlovni tekshiradi va tasdiqlaydi (frontend keyingi
 * so'rovlarda shu tenant id'ni ishlatadi).
 */
class TenantController extends HrController
{
    public function switch(Request $request): JsonResponse
    {
        abort_unless(
            $this->actor()->hasRole('super-admin') || $this->actor()->hasRole('viloyat-admin'),
            403,
        );

        $tenantId = $request->validate([
            'tenant_id' => ['nullable', 'string', 'exists:departments,id'],
        ])['tenant_id'] ?? null;

        // Tenant top-level bo'lishi tekshiruvi
        if ($tenantId !== null) {
            $isTenant = Department::query()->whereNull('parent_id')->where('id', $tenantId)->exists();
            abort_unless($isTenant, 422, 'Bu hokimlik tenant emas');
        }

        return response()->json([
            'tenant' => $tenantId,
            'is_global' => $tenantId === null,
        ]);
    }
}
