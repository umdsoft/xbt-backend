<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** In-app bildirishnoma (TZ 5.8). */
class Notification extends Model
{
    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'notifications';

    /** @var array<int, string> */
    public const TYPES = [
        'task.pending_review',   // hisobot tasdiq kutmoqda
        'task.returned',         // hisobot qaytarildi
        'task.deadline_near',    // muddat yaqin
        'task.overdue',          // muddat buzildi (eskalatsiya)
        'employment.pending',    // bandlik arizasi soliq tasdigʻini kutmoqda
        'employment.returned',
        'case.overdue',          // muammo SLA buzildi
        'youth.pending',         // reyestrga yangi taklif tushdi
    ];

    protected $fillable = [
        'user_id', 'type', 'title', 'body', 'link', 'entity_type', 'entity_id', 'read_at',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
