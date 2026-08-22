<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Sektoral yo'nalish (bandlik, ta'lim, soliq...). Bandlik zanjiri (F3) rolga
 * emas, tashkilot turi + shu sektorga bog'lanadi — yangi tasdiqlovchi kodsiz
 * qo'shiladi.
 */
class Sector extends Model
{
    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'sectors';

    protected $fillable = ['code', 'name_cyr', 'name_lat', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
