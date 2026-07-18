<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use App\Domains\Hr\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class HokimYordamchisi extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use LogsActivity;
    use SoftDeletes;

    protected $connection = 'hr';

    protected $table = 'hokim_yordamchilari';

    public const TENANT_COLUMN = 'hokimlik_id';

    protected $fillable = [
        'hokimlik_id', 'user_id', 'full_name_cyr', 'phone',
        'direction', 'mahalla_id', 'start_date', 'end_date',
        'is_active', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class);
    }

    public function mahalla(): BelongsTo
    {
        return $this->belongsTo(Mahalla::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(HyAssignment::class)->orderByDesc('due_date');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['full_name_cyr', 'direction', 'is_active'])
            ->logOnlyDirty();
    }
}
