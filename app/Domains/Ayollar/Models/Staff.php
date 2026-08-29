<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Foydalanuvchi profili — DOIRA MANBAI.
 *
 * Markaziy `auth.users` kim ekanini biladi, bu yozuv esa u NIMANI ko'rishini.
 * Bu yozuvsiz rol hech narsa ko'rmaydi: «rol bor, doira yo'q» holati ataylab
 * bo'sh natija beradi — doirasiz rolga butun viloyatni ochib qo'yish
 * xavfsizlik teshigi bo'lardi.
 */
class Staff extends Model
{
    use HasUuids;

    protected $connection = 'ayollar';

    protected $table = 'staff';

    protected $fillable = [
        'user_id', 'region_id', 'district_id', 'mahalla_id',
        'org_code', 'position', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
