<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use App\Domains\Mahalla\Models\Master\District;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Choraklik/oylik tuman reytingi (advisor.rankings) — spec §8. score KPI ijro % +
 * topshiriq ijro + loyihadan (RankingService::compute deterministik hisoblaydi),
 * so'ng tartiblab rank yoziladi.
 */
class Ranking extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'rankings';

    protected $fillable = ['period', 'district_id', 'score', 'rank', 'computed_at'];

    protected $casts = [
        'score' => 'decimal:2',
        'rank' => 'integer',
        'computed_at' => 'datetime',
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }
}
