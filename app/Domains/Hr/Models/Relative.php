<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $relationship_type
 * @property string $full_name_cyr
 * @property int $birth_year
 * @property string $birth_place
 * @property bool $is_deceased
 * @property int|null $deceased_year
 * @property string $workplace_and_position
 * @property string|null $former_position
 * @property string $residence_full
 */
class Relative extends Model
{
    use HasFactory;
    use HasUuids;

    protected $connection = 'hr';

    protected $table = 'employee_relatives';

    protected $fillable = [
        'employee_id',
        'relationship_type',
        'full_name_cyr',
        'birth_year',
        'birth_place',
        'is_deceased',
        'deceased_year',
        'workplace_and_position',
        'former_position',
        'residence_full',
    ];

    protected function casts(): array
    {
        return [
            'birth_year' => 'integer',
            'is_deceased' => 'boolean',
            'deceased_year' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
