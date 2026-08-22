<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foydalanuvchi profili — KO'RISH DOIRASINING MANBAI.
 * Bu yozuvsiz (yoki nofaol bo'lsa) rol hech narsa ko'rmaydi (fail-closed).
 */
class Staff extends Model
{
    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'staff';

    protected $fillable = ['user_id', 'org_id', 'position', 'can_patronage', 'is_active'];

    protected function casts(): array
    {
        return ['can_patronage' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Organization, Staff> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
