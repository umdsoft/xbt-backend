<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Topshiriq kategoriyasi (arxiv/qidiruv uchun): "Президент топшириғи",
 * "Вазирлик сўрови", "Рейтинг"... Katalog migratsiyada seed qilinadi.
 */
class TaskCategory extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'task_categories';

    protected $fillable = ['name', 'sort_order', 'active'];

    protected $casts = ['active' => 'boolean', 'sort_order' => 'integer'];
}
