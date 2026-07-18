<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * UUID primary key uchun Spatie Permission'ni kengaytirish (HR domeni, `hr` ulanishi).
 */
class Permission extends SpatiePermission
{
    use HasUuids;

    protected $connection = 'hr';
}
