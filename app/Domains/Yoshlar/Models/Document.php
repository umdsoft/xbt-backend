<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Barcha modullar uchun yagona hujjat jadvali (TZ 5.7).
 *
 * VERSIYALASH: bir xil obyektga bir xil nomli fayl qayta yuklansa, eskisi
 * O'CHIRILMAYDI — `version + 1` bilan yangi qator qo'shiladi. Nazorat
 * organiga «shartnomaning qaysi tahriri qachon yuklangan» ko'rinishi kerak.
 */
class Document extends Model
{
    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'documents';

    /** @var array<int, string> */
    public const ENTITY_TYPES = ['task', 'case', 'employment', 'patronage', 'youth', 'protocol'];

    /** @var array<int, string> */
    public const CATEGORIES = ['shartnoma', 'buyruq', 'dalolatnoma', 'ariza', 'hisobot', 'foto', 'boshqa'];

    /**
     * Ruxsat etilgan kengaytmalar — OQ ro'yxat.
     *
     * `svg` ataylab YO'Q: u ichida skript tashiy oladi va brauzerda
     * ochilganda XSS ga aylanadi.
     */
    public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'heic'];

    public const MAX_SIZE = 25 * 1024 * 1024;

    protected $fillable = [
        'entity_type', 'entity_id', 'district_id', 'category',
        'original_name', 'stored_path', 'mime', 'size', 'sha256',
        'version', 'uploaded_by', 'uploaded_at',
    ];

    /** Disk yo'li javobga TUSHMAYDI: u serverdagi ichki joylashuv. */
    protected $hidden = ['stored_path'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'size' => 'integer', 'version' => 'integer'];
    }
}
