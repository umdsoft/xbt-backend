<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * mahalla domenidagi foydalanuvchi PROFILI (mahalla.users) — bir xil id
 * markaziy auth.users bilan. Faqat domenga xos ma'lumot: geo-scope.
 * Identifikatsiya (login/parol) markazda (App\Models\User).
 */
class MahallaProfile extends Model
{
    use HasUuids;

    protected $connection = 'mahalla';

    protected $table = 'users';

    // Eslatma: mahalla.users — eski to'liq auth jadvali (password NOT NULL). Endi
    // identifikatsiya markazda (auth.users); password bu yerda faqat legacy ustunni
    // to'ldirish uchun (auth'dagi hash nusxasi). Login/parol tekshiruvi auth.users'da.
    protected $fillable = ['id', 'name', 'login', 'password', 'district_id', 'mahalla_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Mahalla\Models\Master\District::class);
    }

    public function mahalla(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Mahalla\Models\Master\Mahalla::class);
    }

    public function streetAssignments(): HasMany
    {
        return $this->hasMany(StreetAssignment::class, 'user_id');
    }
}
