<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Models;

use App\Domains\Mahalla\Models\Master\Street;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HONADON — mas'ul xodim ↔ ko'cha (master) biriktiruvi.
 */
class StreetAssignment extends Model
{
    protected $connection = 'mahalla';

    use HasUuids;

    protected $fillable = ['street_id', 'user_id', 'assigned_by'];

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
