<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Individual ish rejasi — qizil toifadagi ayol bo'yicha chora. */
class WorkPlan extends Model
{
    use HasUuids;

    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    protected $connection = 'ayollar';

    protected $table = 'work_plans';

    protected $fillable = [
        'woman_id', 'mahalla_id', 'district_id', 'problem_codes', 'action',
        'responsible_user_id', 'deadline', 'status', 'result', 'created_by',
    ];

    protected function casts(): array
    {
        return ['problem_codes' => 'array', 'deadline' => 'date'];
    }
}
