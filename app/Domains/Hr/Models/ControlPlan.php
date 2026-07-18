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

class ControlPlan extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use LogsActivity;
    use SoftDeletes;

    public const TENANT_COLUMN = 'hokimlik_id';

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'title', 'document_number', 'document_date',
        'status', 'status_date', 'created_by', 'hokimlik_id',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ControlPlan $plan) {
            $plan->uuid ??= (string) Str::uuid();
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ControlPlanItem::class)->orderBy('sort_order');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'status', 'document_number'])
            ->logOnlyDirty();
    }
}
