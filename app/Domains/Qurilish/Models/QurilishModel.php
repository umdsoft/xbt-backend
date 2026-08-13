<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Qurilish domeni modellari uchun umumiy poydevor: `qurilish` ulanishi + uuid kalit.
 *
 * Ulanish modelda qotirilgan (config'dagi `qurilish` — search_path
 * `qurilish,master,public`), shuning uchun so'rovlar hech qachon boshqa
 * domen schema'siga tushib ketmaydi.
 */
abstract class QurilishModel extends Model
{
    use HasUuids;

    protected $connection = 'qurilish';

    public $incrementing = false;

    protected $keyType = 'string';
}
