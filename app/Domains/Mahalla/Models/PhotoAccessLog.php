<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RASM KO'RISH AUDIT jurnali — kim, qachon, qaysi IP'dan qaysi rasmni ko'rdi.
 * Append-only (created_at/updated_at yo'q — faqat accessed_at).
 */
class PhotoAccessLog extends Model
{
    use HasUuids;

    protected $connection = 'mahalla';

    protected $table = 'photo_access_logs';

    public $timestamps = false;

    protected $fillable = [
        'house_photo_id', 'user_id', 'ip', 'accessed_at',
    ];

    protected $casts = [
        'accessed_at' => 'datetime',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(HousePhoto::class, 'house_photo_id');
    }
}
