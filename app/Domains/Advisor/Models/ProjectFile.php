<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Loyiha fayli (maxfiy disk) — URL orqali ochilmaydi, faqat vakolatli stream
 * route orqali; tuman FAQAT o'z tumani fayllarini ko'radi (spec §6).
 */
class ProjectFile extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'project_files';

    protected $fillable = [
        'project_id', 'path', 'original_name', 'mime', 'size_bytes', 'uploaded_by',
    ];

    protected $casts = ['size_bytes' => 'integer'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
