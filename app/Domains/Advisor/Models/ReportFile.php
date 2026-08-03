<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hisobot dalili (moslashuvchan): fayl | foto | havola | GPS (spec §4, §5).
 * Fayl/foto -> `path` (maxfiy disk); havola -> `url`; GPS -> `geo` jsonb.
 */
class ReportFile extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'report_files';

    protected $fillable = [
        'report_id', 'kind', 'path', 'url', 'geo',
        'original_name', 'mime', 'size_bytes', 'uploaded_by',
    ];

    protected $casts = ['geo' => 'array', 'size_bytes' => 'integer'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(TaskReport::class, 'report_id');
    }
}
