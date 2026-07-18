<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use App\Domains\Hr\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class YouthMeeting extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use LogsActivity;
    use SoftDeletes;

    public const TENANT_COLUMN = 'hokimlik_id';

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'hokimlik_id', 'mahalla_id', 'chairman_id',
        'meeting_date', 'meeting_time', 'location',
        'participants_count', 'agenda', 'notes', 'ai_summary',
        'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'meeting_date' => 'date',
            'participants_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (YouthMeeting $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function mahalla(): BelongsTo
    {
        return $this->belongsTo(Mahalla::class);
    }

    public function chairman(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'chairman_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'created_by');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(CitizenAppeal::class, 'youth_meeting_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['meeting_date', 'status', 'participants_count'])
            ->logOnlyDirty();
    }
}
