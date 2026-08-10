<?php

declare(strict_types=1);

namespace App\Domains\Sport\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trener → mahalla biriktirish + mahalla konteksti (sport.trainer_mahallas).
 * `mahalla_id` — master.mahallas (nom MahallaMatcher bilan bog'lanadi; moslanmasa
 * null + xom nom saqlanadi). Yoshlar/aholi sonlari — 2026 reja hisoblarи (kasr).
 */
class TrainerMahalla extends Model
{
    use HasUuids;

    protected $connection = 'sport';

    protected $table = 'trainer_mahallas';

    /** @var array<int, string> */
    protected $fillable = [
        'trainer_id', 'mahalla_id', 'mahalla_name_raw', 'district_id', 'schools',
        'sport_objects_count', 'youth_7_17', 'youth_7_30', 'youth_14_30',
        'youth_16_30', 'youth_30_50', 'pop_30plus', 'pop_total', 'plan_2026',
        'plan_percent',
    ];

    protected $casts = [
        'sport_objects_count' => 'integer',
        'youth_7_17' => 'float', 'youth_7_30' => 'float', 'youth_14_30' => 'float',
        'youth_16_30' => 'float', 'youth_30_50' => 'float', 'pop_30plus' => 'float',
        'pop_total' => 'integer', 'plan_2026' => 'float', 'plan_percent' => 'float',
    ];

    /** @return BelongsTo<Trainer, $this> */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'trainer_id');
    }
}
