<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Models;

use App\Domains\Mahalla\Models\Master\District;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MurojAAT foydalanuvchi profili (murojaat.profiles). Markaziy auth.users bilan
 * user_id orqali bog'lanadi (FK yo'q). district (viloyat = null). advisor naqshi.
 */
class MurojaatProfile extends Model
{
    use HasUuids;

    protected $connection = 'murojaat';

    protected $table = 'profiles';

    protected $fillable = ['user_id', 'level', 'district_id', 'position', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }
}
