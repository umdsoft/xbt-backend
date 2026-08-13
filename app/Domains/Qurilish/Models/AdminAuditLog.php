<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

/**
 * Administrator amallari jurnali — hisob ochish, dastur/soha o'zgartirish.
 *
 * Obyekt jurnalidan alohida: bu amallar obyektga tegishli emas, lekin
 * tizimdagi eng nozik amallar aynan shular (kimga kirish berildi, kim
 * dasturni o'chirdi). Yozuvlar O'ZGARTIRILMAYDI — faqat qo'shiladi.
 */
class AdminAuditLog extends QurilishModel
{
    protected $table = 'admin_audit_log';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];
}
