<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Models;

use App\Domains\Advisor\Outreach\Models\Concerns\WritesTimezoneOffset;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One sender mailbox of the pool (PLAN-send.md §1a). Only state lives here;
 * SMTP host/login/password belong to the Laravel mailer named in `mailer`,
 * configured from .env.
 */
class Sender extends Model
{
    use HasUuids;
    use WritesTimezoneOffset;

    protected $connection = 'advisor';

    protected $table = 'outreach_senders';

    protected $fillable = [
        'email', 'mailer', 'display_name', 'warmup_started_on', 'daily_cap_max', 'active', 'paused_at', 'paused_reason',
    ];

    protected $casts = [
        'warmup_started_on' => 'date',
        'daily_cap_max' => 'integer',
        'active' => 'boolean',
        'paused_at' => 'datetime',
    ];

    public function isUsable(): bool
    {
        return $this->active && $this->paused_at === null;
    }

    /**
     * Letters this mailbox may send on `$day`: the warm-up ramp from
     * config('outreach.send.warmup') week by week, then daily_cap_max.
     */
    public function capOn(Carbon $day): int
    {
        // Compare calendar dates only: `$day` is a local (Tashkent) day, the
        // column is a plain date — mixing their time zones shifts the ramp.
        $today = Carbon::parse($day->toDateString(), 'UTC');
        $start = Carbon::parse($this->warmup_started_on->toDateString(), 'UTC');

        if ($today->lt($start)) {
            return 0;
        }

        $ramp = (array) config('outreach.send.warmup', [10, 20, 30]);
        $week = intdiv((int) $start->diffInDays($today), 7);

        return min($this->daily_cap_max, (int) ($ramp[$week] ?? $this->daily_cap_max));
    }
}
