<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $start_year
 * @property int|null $end_year
 * @property string $organization_full
 * @property string $position_full
 * @property string|null $order_number
 * @property int $sort_order
 */
class WorkHistory extends Model
{
    use HasFactory;
    use HasUuids;

    protected $connection = 'hr';

    protected $table = 'employee_work_history';

    protected $fillable = [
        'employee_id',
        'start_year',
        'end_year',
        'organization_full',
        'position_full',
        'order_number',
        'order_date',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'start_year' => 'integer',
            'end_year' => 'integer',
            'order_date' => 'date',
            'sort_order' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
