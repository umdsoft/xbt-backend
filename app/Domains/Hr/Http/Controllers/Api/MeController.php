<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

/**
 * Жорий HR фойдаланувчиси — роль, рухсатлар, тенант.
 *
 * SPA frontend бунга таянади: рухсатга қараб тугма/бўлимларни кўрсатади ёки
 * яширади. Илгари бундай endpoint йўқ эди — frontend фақат dashboard `mode`
 * ва 403 хатоларга таянарди, бу эса UI'ни аниқ бошқаришга имкон бермасди.
 *
 * Рухсатлар server tomonda ҳар доим policy/middleware билан мажбурланади —
 * бу endpoint фақат UI учун "нимани кўрсатиш" маслаҳати, ҳимоя эмас.
 */
class MeController extends HrController
{
    public function __invoke(): JsonResponse
    {
        $actor = $this->actor();
        $tenant = $this->tenant();

        return response()->json([
            'user' => [
                'id' => $actor->id,
                'name' => $actor->name,
                'login' => $actor->login ?? null,
            ],
            'roles' => $actor->getRoleNames()->values(),
            'permissions' => $actor->getAllPermissions()->pluck('name')->values(),
            'tenant' => [
                // super-admin/viloyat-admin uchun null bo'ladi (global ko'rinish).
                'hokimlik_id' => $tenant->id(),
                'is_global' => $tenant->isGlobal(),
            ],
        ]);
    }
}
