<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Qizil belgi — anketa javobidan AVTOMATIK chiqadi, qo'lda qo'yilmaydi.
 * Toifani o'zgartirmaydi, ustiga qo'shiladi.
 */
class AnketaRedFlag extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'ayollar';

    protected $table = 'anketa_red_flags';

    protected $fillable = ['anketa_id', 'flag_code', 'source_question', 'created_at'];

    protected function casts(): array
    {
        return ['source_question' => 'integer', 'created_at' => 'datetime'];
    }
}
