<?php

declare(strict_types=1);

namespace App\Domains\Sport\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Seleksioner trener (sport.trainers). Mahallalarga biriktirilган; Excel import
 * orqali yaratiladi. PII (passport/ЖШИР) SAQLANMAYDI. `phone` operatsion —
 * public sahifada ko'rsatilmaydi.
 */
class Trainer extends Model
{
    use HasUuids;

    protected $connection = 'sport';

    protected $table = 'trainers';

    /** @var array<int, string> */
    protected $fillable = [
        'district_id', 'full_name', 'phone', 'birth_date', 'age', 'workplace',
        'sport_type', 'specialization_raw', 'staff_unit', 'uniform_size',
        'other_workplace', 'source',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'age' => 'integer',
        'staff_unit' => 'float',
    ];

    /** @return HasMany<TrainerMahalla, $this> */
    public function mahallas(): HasMany
    {
        return $this->hasMany(TrainerMahalla::class, 'trainer_id');
    }
}
