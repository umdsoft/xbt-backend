<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Otaliq: mentor (xodim) <-> yosh.
 *
 * Bitta yosh bir vaqtda bitta faol otaliqda bo'ladi (DB'da partial unique) —
 * ikki mentor biriktirilsa javobgarlik yuvilib ketardi.
 */
class Patronage extends Model
{
    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'patronage';

    protected $fillable = [
        'youth_id', 'mentor_staff_id', 'district_id',
        'started_at', 'ended_at', 'is_active', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'date', 'ended_at' => 'date', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Youth, Patronage> */
    public function youth(): BelongsTo
    {
        return $this->belongsTo(Youth::class, 'youth_id');
    }

    /** @return BelongsTo<Staff, Patronage> */
    public function mentor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'mentor_staff_id');
    }

    /** @return HasMany<PatronageLog> */
    public function logs(): HasMany
    {
        return $this->hasMany(PatronageLog::class, 'patronage_id')->orderByDesc('log_date');
    }
}
