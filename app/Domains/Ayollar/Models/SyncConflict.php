<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Offline sinxron konflikti.
 *
 * AVTOMATIK HAL QILINMAYDI (promt §14): server «qaysi biri to'g'ri» ni
 * bilmaydi — ikkala versiya ham haqiqiy tashrifdan kelgan bo'lishi mumkin.
 * Faolga tanlov ekrani ko'rsatiladi.
 */
class SyncConflict extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    protected $connection = 'ayollar';

    protected $table = 'sync_conflicts';

    protected $fillable = [
        'entity', 'entity_id', 'client_uuid', 'device_id',
        'server_version', 'client_version', 'status', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'server_version' => 'array',
            'client_version' => 'array',
            'resolved_at' => 'datetime',
        ];
    }
}
