<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Umumiy o'zgarishlar jurnali. */
class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'ayollar';

    protected $table = 'audit_log';

    protected $fillable = ['user_id', 'action', 'entity_type', 'entity_id', 'changes', 'ip', 'created_at'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }
}
