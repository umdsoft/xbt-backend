<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Fuqaro murojaati (murojaat.appeals). 44 xom maydon (Excel) + serverda hisoblangan
 * normalizatsiya (natija_holat_norm, is_kechikkan, is_sayyor, manba_type, stat_holat…).
 * Faqat import orqali yaratiladi (CRUD yo'q) — bu tahlil tizimi.
 */
class Appeal extends Model
{
    use HasUuids;

    protected $connection = 'murojaat';

    protected $table = 'appeals';

    /** @var array<int, string> */
    protected $fillable = [
        'session_id', 'district_id',
        // xom
        'tr', 'murojaat_raqami', 'masala_raqami', 'qaerdan', 'kelgan_sana', 'muddat_kun',
        'nazoratchi', 'yuqori_tashkilot', 'ijrochi', 'murojaat_turi', 'jamoaviy',
        'yashash_hudud', 'yashash_tuman', 'sektor', 'mahalla', 'manzil', 'fuqaro_id',
        'familiya', 'ism', 'otasi_ismi', 'telefon', 'jinsi', 'tugilgan_sana', 'bandlik',
        'soha', 'yonalish', 'masala', 'natija_toifa', 'natija_holat', 'javob_kiritilgan',
        'javob_yuborilgan', 'javob_tasdiqlangan', 'korib_chiqish_kun', 'kechikish_30dan',
        'kechikib_yopilgan', 'kechikib_30dan', 'takroriylik', 'kiritgan_tashkilot',
        'sayyor_tashkilot', 'sayyor_rahbar', 'rahbar_lavozim', 'ijrochi_hudud',
        'ijrochi_tuman', 'pinfl',
        // hisoblangan
        'natija_holat_norm', 'is_kechikkan', 'is_sayyor', 'manba_type', 'kun_otgan',
        'kelgan_sana_d', 'kelgan_yil', 'kelgan_oy', 'stat_holat',
    ];

    protected $casts = [
        'muddat_kun' => 'integer',
        'korib_chiqish_kun' => 'integer',
        'kechikish_30dan' => 'integer',
        'kechikib_yopilgan' => 'integer',
        'kechikib_30dan' => 'integer',
        'kun_otgan' => 'integer',
        'kelgan_yil' => 'integer',
        'kelgan_oy' => 'integer',
        'is_kechikkan' => 'boolean',
        'is_sayyor' => 'boolean',
        'kelgan_sana_d' => 'date',
    ];
}
