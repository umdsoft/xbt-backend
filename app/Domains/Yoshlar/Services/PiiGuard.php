<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Domains\Yoshlar\Models\PiiAccessLog;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Models\User;
use Illuminate\Support\Facades\Request;

/**
 * Maxfiy maydonlarni ochish — YAGONA yo'l. Har ochilish jurnalga tushadi.
 *
 * NEGA ALOHIDA SERVIS: `$hidden` ni kontrollerda `makeVisible()` bilan yechish
 * mumkin, lekin unda jurnalni unutib qo'yish oson bo'lardi. Bu yerda ochilish
 * va jurnal bitta amalda — birini bajarib, ikkinchisini o'tkazib bo'lmaydi.
 */
class PiiGuard
{
    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{pinfl: ?string, passport_series: ?string, passport_number: ?string} */
    public function reveal(User $user, Youth $youth): array
    {
        abort_unless(
            $this->access->can($user, 'yoshlar.pii.reveal'),
            403,
            'Maxfiy ma’lumotni ko‘rish huquqingiz yo‘q.',
        );

        PiiAccessLog::query()->create([
            'user_id' => $user->id,
            'youth_id' => $youth->id,
            'fields' => 'pinfl,passport',
            'ip' => Request::ip(),
            'created_at' => now(),
        ]);

        $this->audit->log($user, 'youth.pii_reveal', 'youth', $youth->id);

        return [
            'pinfl' => $youth->pinfl,
            'passport_series' => $youth->passport_series,
            'passport_number' => $youth->passport_number,
        ];
    }
}
