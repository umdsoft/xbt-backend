<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

/** Tuman balansi — MFY balanslari YIG'INDISI (og'ish 0 bo'lishi shart). */
class DistrictBalance extends Balance
{
    protected $table = 'district_balances';

    protected $fillable = [
        'district_id', 'period_year', 'period_month', 'metrics',
        'total', 'green', 'yellow', 'red', 'status',
        'closed_at', 'closed_by', 'return_reason', 'calculated_at',
    ];

    public function ownerKey(): string
    {
        return 'district_id';
    }

    public function levelCode(): string
    {
        return 'district';
    }
}
