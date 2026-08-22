<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Otaliq jurnali — uchrashuv, harakat, hal etilgan muammo.
 *
 * KPI «faollik» shu jurnal asosida o'lchanadi, biriktirish fakti bilan
 * emas: aks holda qog'ozda otaliq bo'lib, amalda hech narsa qilinmasligi
 * mumkin edi.
 */
class PatronageLog extends Model
{
    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'patronage_logs';

    /** @var array<int, string> */
    public const KINDS = ['uchrashuv', 'harakat', 'hal_etilgan_muammo', 'izoh'];

    protected $fillable = ['patronage_id', 'log_date', 'kind', 'note', 'case_id', 'created_by'];

    protected function casts(): array
    {
        return ['log_date' => 'date'];
    }

    /** @return BelongsTo<Patronage, PatronageLog> */
    public function patronage(): BelongsTo
    {
        return $this->belongsTo(Patronage::class, 'patronage_id');
    }
}
