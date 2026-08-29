<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Uch daraja balansi uchun umumiy asos (MFY / tuman / viloyat).
 *
 * NEGA UCHTA JADVAL, BITTA EMAS: agregatsiya darajalari har xil tezlikda
 * o'zgaradi va har xil imzo oqimiga ega. Bitta jadvalda `level` ustuni
 * bilan bo'lsa, har so'rovga `where level = ?` qo'shilardi va noyob
 * indekslar (`hudud + davr`) darajalar bo'ylab aralashib ketardi.
 */
abstract class Balance extends Model
{
    use HasUuids;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const APPROVED = 'approved';

    public const RETURNED = 'returned';

    protected $connection = 'ayollar';

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'total' => 'integer',
            'green' => 'integer',
            'yellow' => 'integer',
            'red' => 'integer',
            'closed_at' => 'datetime',
            'calculated_at' => 'datetime',
        ];
    }

    /** Hududni ko'rsatuvchi ustun nomi (`mahalla_id`, ...). */
    abstract public function ownerKey(): string;

    /** `balance_signatures.balance_type` qiymati. */
    abstract public function levelCode(): string;

    /**
     * BUZILMAS TENGLIK: yashil + sariq = jami.
     *
     * Yopishdan oldin tekshiriladi. Buzilgan bo'lsa yopish BLOKLANADI —
     * noto'g'ri balans imzolangandan keyin uni tuzatish ancha qimmat.
     */
    public function isConsistent(): bool
    {
        return $this->green + $this->yellow === $this->total
            && $this->red <= $this->total;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::OPEN, self::RETURNED], true);
    }
}
