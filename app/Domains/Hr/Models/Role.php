<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * UUID primary key uchun Spatie Role'ni kengaytirish (HR domeni, `hr` ulanishi).
 */
class Role extends SpatieRole
{
    use HasUuids;

    protected $connection = 'hr';
}
