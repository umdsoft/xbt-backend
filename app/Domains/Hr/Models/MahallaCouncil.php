<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use App\Domains\Hr\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MahallaCouncil extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const TENANT_COLUMN = 'hokimlik_id';

    protected $connection = 'hr';

    protected $fillable = [
        'hokimlik_id', 'mahalla_id', 'name', 'phone', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function mahalla(): BelongsTo
    {
        return $this->belongsTo(Mahalla::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(CouncilMember::class, 'council_id')->orderBy('role');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(CouncilDecision::class, 'council_id');
    }
}
