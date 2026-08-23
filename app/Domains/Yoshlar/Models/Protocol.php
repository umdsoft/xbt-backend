<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rasmiy hujjat — topshiriqlarning manbai.
 *
 * Uch xil hujjat bir jadvalda, chunki ularning TUZILISHI bir xil: raqamli
 * bandlar, muddat, mas'ul. Farqi faqat ekranda koʻrsatiladigan ustunlarda
 * (yoʻl xaritasida «Murojaatchi» ustuni bor, bayonnomada yoʻq) — bu esa
 * alohida jadval ochishga arzimaydi.
 */
class Protocol extends Model
{
    /** Hokim yigʻilishi bayonnomasi — «6. ... Muddat – 1-noyabr». */
    public const TYPE_BAYONNOMA = 'bayonnoma';

    /** Yoʻl xaritasi — murojaatchi ustuni bor jadval. */
    public const TYPE_ROADMAP = 'yol_xaritasi';

    /** Yillik chora-tadbirlar rejasi — boʻlimlarga ajratilgan. */
    public const TYPE_ACTION_PLAN = 'chora_tadbir';

    /** @var array<int, string> */
    public const TYPES = [self::TYPE_BAYONNOMA, self::TYPE_ROADMAP, self::TYPE_ACTION_PLAN];

    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'protocols';

    protected $fillable = [
        'number', 'protocol_date', 'topic', 'description', 'file_path', 'file_name',
        'issued_by', 'created_by', 'type', 'event_title', 'year',
    ];

    protected function casts(): array
    {
        return ['protocol_date' => 'date', 'year' => 'integer'];
    }

    /**
     * Bandlar — HUJJATDAGI tartibda.
     *
     * `sort_order` boʻyicha, `created_at` boʻyicha EMAS: bandlar tizimga
     * ketma-ket kiritilmasligi mumkin (avval 6-band, keyin 2-band), ammo
     * ekranda ular doim qogʻozdagi tartibda turishi shart.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'protocol_id')
            ->orderBy('sort_order')
            ->orderBy('item_number');
    }

    /** Yoʻl xaritasida murojaatchi ustuni koʻrsatiladi. */
    public function showsApplicant(): bool
    {
        return $this->type === self::TYPE_ROADMAP;
    }
}
