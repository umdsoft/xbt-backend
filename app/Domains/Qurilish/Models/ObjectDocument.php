<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Obyekt hujjati — versiyalangan; bir xil `sha256` uchun disk nusxasi
 * qayta ishlatiladi (yangi qator, eski fayl).
 */
class ObjectDocument extends QurilishModel
{
    use SoftDeletes;

    protected $table = 'object_documents';

    protected $guarded = [];

    /** @var array<int, string> */
    public const CATEGORIES = ['lsd', 'ekspertiza', 'shartnoma', 'dalolatnoma', 'surat', 'boshqa'];

    /** Ruxsat etilgan kengaytmalar (oq ro'yxat — spec 4.2). */
    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx'];

    /** Fayl hajmi chegarasi — 25 MB. */
    public const MAX_SIZE = 25 * 1024 * 1024;

    protected $casts = [
        'size' => 'integer',
        'version' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    public function object(): BelongsTo
    {
        return $this->belongsTo(ConstructionObject::class, 'object_id');
    }
}
