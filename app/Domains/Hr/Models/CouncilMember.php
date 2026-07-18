<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouncilMember extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'council_id', 'user_id', 'full_name', 'role', 'phone', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function council(): BelongsTo
    {
        return $this->belongsTo(MahallaCouncil::class, 'council_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class);
    }
}
