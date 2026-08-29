<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

/** MFY balansi — anketalardan bevosita hisoblanadi. */
class MahallaBalance extends Balance
{
    protected $table = 'mahalla_balances';

    protected $fillable = [
        'mahalla_id', 'period_year', 'period_month', 'metrics',
        'total', 'green', 'yellow', 'red', 'status',
        'closed_at', 'closed_by', 'return_reason', 'calculated_at',
    ];

    public function ownerKey(): string
    {
        return 'mahalla_id';
    }

    public function levelCode(): string
    {
        return 'mahalla';
    }
}
