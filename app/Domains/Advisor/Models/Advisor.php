<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use App\Domains\Mahalla\Models\Master\District;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADVISOR domeni — hokim maslahatchisi profili (advisor.advisors).
 *
 * Identifikatsiya markazda (auth.users) — bu jadval faqat domenga xos ma'lumot:
 * daraja (level), tuman (district_id), lavozim, telefon. `user_id` markaziy
 * auth.users.id ga teng (mahalla profili naqshi).
 */
class Advisor extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'advisors';

    protected $fillable = ['user_id', 'level', 'district_id', 'position', 'phone', 'active'];

    protected $casts = ['active' => 'boolean'];

    /**
     * Tuman (master.districts) — viloyat/bo'linma maslahatchisi uchun null.
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /**
     * Markaziy identifikatsiya (auth.users) — login/ism/telefon manbai.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
