<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppealStatusHistory extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'hr';

    protected $table = 'appeal_status_history';

    protected $fillable = [
        'appeal_id', 'from_status', 'to_status', 'changed_by', 'reason', 'changed_at',
    ];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(CitizenAppeal::class, 'appeal_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'changed_by');
    }
}
