<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HyAssignment extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $table = 'hy_assignments';

    protected $fillable = [
        'hokim_yordamchisi_id', 'title', 'description',
        'due_date', 'status', 'result_notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
        ];
    }

    public function hokimYordamchisi(): BelongsTo
    {
        return $this->belongsTo(HokimYordamchisi::class, 'hokim_yordamchisi_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'created_by');
    }
}
