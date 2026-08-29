<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Balans imzosi. Har idora FAQAT o'ziga tegishli qatorlarni tasdiqlaydi —
 * qaysi qator kimniki ekani `metric_registry.owner_org_code` da.
 */
class BalanceSignature extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const SIGNED = 'signed';

    public const RETURNED = 'returned';

    /**
     * Tuman darajasidagi 8 imzo — TARTIB MUHIM.
     *
     * `economy_finance` ro'yxatda OXIRGI va u faqat qolgan 7 tasi
     * qo'yilgandan keyin ochiladi (promt §5.1). Tartibni massiv belgilaydi,
     * kod emas — yangi idora qo'shilsa, faqat shu ro'yxat o'zgaradi.
     */
    public const DISTRICT_ORGS = [
        'family_dept',
        'mahalla_union',
        'iib',
        'health',
        'preschool_school',
        'poverty_reduction',
        'tax',
        'economy_finance',
    ];

    /** MFY darajasidagi 6 imzo. */
    public const MAHALLA_ORGS = [
        'rais',
        'hokim_yordamchisi',
        'profilaktika_inspektori',
        'yoshlar_yetakchisi',
        'xotin_qizlar_faoli',
        'ijtimoiy_xodim',
    ];

    /** Yakuniy imzo — undan oldin qolgan hammasi kerak. */
    public const FINAL_ORG = 'economy_finance';

    protected $connection = 'ayollar';

    protected $table = 'balance_signatures';

    protected $fillable = [
        'balance_type', 'balance_id', 'org_code', 'user_id',
        'signed_at', 'signature_data', 'comment', 'status',
    ];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }
}
