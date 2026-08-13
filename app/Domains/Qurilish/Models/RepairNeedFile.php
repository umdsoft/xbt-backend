<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Ta'mirtalab yozuvga biriktirilgan fayl (surat/hujjat). */
class RepairNeedFile extends QurilishModel
{
    use SoftDeletes;

    protected $table = 'repair_need_files';

    protected $guarded = [];

    protected $casts = [
        'size' => 'integer',
        'version' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    public function repairNeed(): BelongsTo
    {
        return $this->belongsTo(RepairNeed::class, 'repair_need_id');
    }
}
