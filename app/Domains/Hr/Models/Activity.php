<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use App\Domains\Hr\Support\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * UUID primary key + tenant-aware audit журнали (HR domeni, `hr` ulanishi).
 *
 * - Ёзувда: hokimlik_id жорий TenantContext дан автоматик тўлдирилади.
 * - Ўқишда: global scope tenant бўйича фильтрлайди (cross-tenant/viloyat/super
 *   бундан мустасно) — бир ҳокимлик админи бошқа ҳокимлик аудитини кўрмайди.
 */
class Activity extends SpatieActivity
{
    use HasUuids;

    protected $connection = 'hr';

    protected static function booted(): void
    {
        // Ёзувда — жорий tenant'ни белгилаш (insert'га global scope таъсир қилмайди).
        static::creating(function (self $activity): void {
            if ($activity->getAttribute('hokimlik_id') === null) {
                $activity->setAttribute('hokimlik_id', app(TenantContext::class)->id());
            }
        });

        // Ўқишда — tenant бўйича фильтр.
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $context = app(TenantContext::class);

            // Global rejim (super-admin / viloyat-admin) — барчасини кўради.
            if ($context->isGlobal() || app()->runningInConsole()) {
                return;
            }

            $tenantId = $context->id();
            if ($tenantId === null) {
                // Non-global HTTP kontekst tenant'siz — FAIL-CLOSED (hech narsa ko'rsatilmaydi).
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($builder->qualifyColumn('hokimlik_id'), $tenantId);
        });
    }
}
