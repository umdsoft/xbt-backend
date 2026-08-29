<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

/** Viloyat balansi — 13 tuman YIG'INDISI (og'ish 0 bo'lishi shart). */
class RegionBalance extends Balance
{
    protected $table = 'region_balances';

    protected $fillable = [
        'region_id', 'period_year', 'period_month', 'metrics',
        'total', 'green', 'yellow', 'red', 'status',
        'closed_at', 'closed_by', 'return_reason', 'calculated_at',
    ];

    public function ownerKey(): string
    {
        return 'region_id';
    }

    public function levelCode(): string
    {
        return 'region';
    }
}
