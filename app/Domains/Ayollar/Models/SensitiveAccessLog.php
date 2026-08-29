<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Maskalangan maydonni ochish jurnali. HECH QACHON o'chirilmaydi.
 *
 * `timestamps = false`: `accessed_at` yagona vaqt. `updated_at` bo'lishi
 * jurnal yozuvi tahrirlanishi mumkindek taassurot berardi — hisobdorlik
 * jurnalida esa bu ma'noga zid.
 */
class SensitiveAccessLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'ayollar';

    protected $table = 'sensitive_access_log';

    protected $fillable = ['user_id', 'woman_id', 'field', 'ip', 'user_agent', 'accessed_at'];

    protected function casts(): array
    {
        return ['accessed_at' => 'datetime'];
    }
}
