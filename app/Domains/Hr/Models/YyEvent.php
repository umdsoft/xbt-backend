<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YyEvent extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $table = 'yy_events';

    protected $fillable = [
        'yoshlar_yetakchisi_id', 'event_type', 'title', 'description',
        'event_date', 'participants_count', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
        ];
    }

    public function yoshlarYetakchisi(): BelongsTo
    {
        return $this->belongsTo(YoshlarYetakchisi::class, 'yoshlar_yetakchisi_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'created_by');
    }
}
