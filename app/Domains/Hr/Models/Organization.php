<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use App\Domains\Hr\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Ташкилот — котибият мудири яратадиган ва топшириқ оладиган ташкилот.
 *
 * @property int $id
 * @property int $hokimlik_id
 * @property int|null $kompleks_id
 * @property string $name_cyr
 * @property string|null $name_lat
 * @property string|null $inn
 * @property string|null $phone
 * @property string|null $address
 * @property int|null $created_by
 * @property bool $is_active
 */
class Organization extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;
    use LogsActivity;
    use SoftDeletes;

    public const TENANT_COLUMN = 'hokimlik_id';

    protected $connection = 'hr';

    protected $fillable = [
        'hokimlik_id',
        'kompleks_id',
        'name_cyr',
        'name_lat',
        'inn',
        'phone',
        'address',
        'created_by',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ===== Муносабатлар =====

    /** Эгаси бўлган комплекс (department, type='kompleks'). */
    public function kompleks(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'kompleks_id');
    }

    /** Ташкилотни яратган котибият мудири. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'created_by');
    }

    /** Ташкилот фойдаланувчилари (admin + ходимлар). */
    public function users(): HasMany
    {
        return $this->hasMany(HrProfile::class);
    }

    /** Ушбу ташкилотга бириктирилган топшириқлар (масъул сифатида). */
    public function responsibilities(): HasMany
    {
        return $this->hasMany(ItemResponsible::class, 'assignee_id')
            ->where('assignee_type', 'organization');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name_cyr', 'inn', 'is_active', 'kompleks_id'])
            ->logOnlyDirty();
    }
}
