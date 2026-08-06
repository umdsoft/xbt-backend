<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MurojAAT import sessiyasi (murojaat.import_sessions). Har Excel yuklash bitta
 * sessiya; is_active = joriy tahlil to'plami. Eskilari arxiv.
 */
class ImportSession extends Model
{
    use HasUuids;

    protected $connection = 'murojaat';

    protected $table = 'import_sessions';

    protected $fillable = [
        'district_id', 'file_name', 'records_count', 'sayyor_count', 'imported_by', 'is_active',
    ];

    protected $casts = [
        'records_count' => 'integer',
        'sayyor_count' => 'integer',
        'is_active' => 'boolean',
    ];

    public function appeals(): HasMany
    {
        return $this->hasMany(Appeal::class, 'session_id');
    }
}
