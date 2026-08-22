<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Hokim chora-tadbiri / protokoli — topshiriqlarning manbai. */
class Protocol extends Model
{
    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'protocols';

    protected $fillable = [
        'number', 'protocol_date', 'topic', 'description', 'file_path', 'issued_by', 'created_by',
    ];

    protected function casts(): array
    {
        return ['protocol_date' => 'date'];
    }

    /** @return HasMany<Task> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'protocol_id');
    }
}
