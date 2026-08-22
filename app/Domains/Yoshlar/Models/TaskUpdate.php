<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ijro hisoboti va uning tasdiqlash zanjiridagi holati.
 *
 * ZANJIR: `sector_review` (sektor boshqarmasi ko'radi) -> `youth_review`
 * (yoshlar boshqarmasi yakunlaydi) -> `approved`. Har bosqichda `returned`
 * ga tushishi mumkin — u holda ijrochi tuzatib qayta yuboradi.
 *
 * Bitta topshiriqda bir vaqtda faqat BITTA ochiq yuborish bo'ladi (DB'da
 * partial unique indeks) — aks holda tasdiqlovchida bir nechta ziddiyatli
 * foiz paydo bo'lardi.
 */
class TaskUpdate extends Model
{
    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'task_updates';

    /** @var array<int, string> */
    public const OPEN_STAGES = ['sector_review', 'youth_review'];

    /** @var array<int, string> */
    public const STAGES = ['sector_review', 'youth_review', 'approved', 'returned'];

    protected $fillable = [
        'task_id', 'progress', 'comment', 'file_path',
        'submitted_by', 'submitted_org_id', 'submitted_at',
        'review_stage', 'sector_reviewed_by', 'sector_reviewed_at',
        'youth_reviewed_by', 'youth_reviewed_at', 'review_comment',
    ];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'submitted_at' => 'datetime',
            'sector_reviewed_at' => 'datetime',
            'youth_reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Task, TaskUpdate> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->review_stage, self::OPEN_STAGES, true);
    }
}
