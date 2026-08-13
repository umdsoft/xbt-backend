<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qurilish domeni foydalanuvchi profili — rol + ko'rish doirasi.
 *
 * `user_id` markaziy `auth.users` ga ishora qiladi (cross-schema FK YO'Q).
 * `organization_id` — buyurtmachi/boshqarma scope'ining manbai; usiz
 * QurilishScope fail-closed ishlaydi (bo'sh natija).
 */
class QurilishProfile extends QurilishModel
{
    protected $table = 'profiles';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
