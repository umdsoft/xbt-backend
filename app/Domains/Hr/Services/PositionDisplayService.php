<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services;

use App\Domains\Hr\Models\ItemResponsible;

/**
 * Lavozim matnini formatlash:
 * - "Хоразм вилояти ҳокимининг маслаҳатчиси"
 * - "Боғот туман ҳокимининг ўринбосари"
 * - oddiy: "Бош мутахассис"
 */
class PositionDisplayService
{
    public function forResponsible(ItemResponsible $resp): ?string
    {
        if (! empty($resp->responsible_position)) {
            return $resp->responsible_position;
        }

        $user = $resp->user;
        if (! $user || ! $user->position) {
            return null;
        }

        $dept = $user->department;
        $pos = $user->position;

        // Ҳокимлик (parent_id = null) — "Вилоят ҳокимининг маслаҳатчиси"
        if ($dept && $dept->parent_id === null) {
            $hokimNomi = str_replace('ҳокимлиги', 'ҳокимининг', $dept->name_cyr);
            $lavozim = mb_strtolower($pos->name_cyr);
            $lavozim = preg_replace('/^ҳоким\s*/u', '', $lavozim);

            return "{$hokimNomi} {$lavozim}";
        }

        return $pos->name_cyr;
    }
}
