<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use App\Domains\Ayollar\Models\SensitiveAccessLog;
use App\Domains\Ayollar\Models\Woman;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Maskalangan maydonni ochish — YAGONA yo'l.
 *
 * `Woman::revealRawPii()` ni to'g'ridan-to'g'ri chaqirish MUMKIN, lekin u
 * jurnal yozmaydi. Shuning uchun controller'lar HAR DOIM shu servis orqali
 * o'tadi va tekshiruv bilan jurnal BIR joyda turadi: birini yozib,
 * ikkinchisini unutib bo'lmaydi.
 *
 * Jurnalsiz ochish — audit nuqtai nazaridan ochilmagan bilan barobar:
 * ma'lumot chiqib ketgan, lekin kim olganini hech kim bilmaydi.
 */
class SensitiveAccessService
{
    public function __construct(
        private readonly AyollarAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Xom PII qiymatini qaytaradi va jurnalga yozadi.
     *
     * @param  array<int, string>  $fields  pinfl | passport | phone
     * @return array<string, string|null>
     *
     * @throws AccessDeniedHttpException
     */
    public function reveal(User $user, Woman $woman, array $fields, Request $request): array
    {
        if (! $this->access->can($user, 'ayollar.pii.reveal')) {
            throw new AccessDeniedHttpException('Maxfiy maydonni ochishga ruxsat yo‘q.');
        }

        $allowed = array_values(array_intersect($fields, ['pinfl', 'passport', 'phone']));

        if ($allowed === []) {
            throw new AccessDeniedHttpException('Noma‘lum maydon.');
        }

        $out = [];
        $rows = [];
        $now = now();

        foreach ($allowed as $field) {
            $out[$field] = $woman->revealRawPii($field);

            $rows[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $user->id,
                'woman_id' => $woman->id,
                'field' => $field,
                'ip' => $request->ip(),
                // 500 belgidan kesiladi: ba'zi qurilmalar juda uzun
                // User-Agent yuboradi va ustun uzunligi cheklangan.
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'accessed_at' => $now,
            ];
        }

        // Jurnal QIYMAT QAYTARISHDAN OLDIN yoziladi. Aks holda javob
        // jo'natilgandan keyin baza uzilsa, ma'lumot chiqib ketardi-yu,
        // izi qolmasdi.
        SensitiveAccessLog::query()->insert($rows);

        // Amallar jurnaliga HAM tushadi — administrator maxfiy jurnalni
        // ochmasdan ham «kimdir PII ochdi» faktini ko'radi.
        $this->audit->log(
            $user,
            'pii.revealed',
            'woman',
            (string) $woman->id,
            ['fields' => $allowed],
            $request,
        );

        return $out;
    }

    /**
     * Qizil toifadagi AYOLLAR RO'YXATINI ko'rishga ruxsat bormi.
     *
     * Alohida tekshiruv: bu PII ochish emas, lekin xuddi shunday nozik —
     * «zo'ravonlik qurbonlari ro'yxati» ismlar bilan birga chiqadi.
     * Promt §6.3: faqat 3 rol.
     */
    public function assertCanSeeRedNames(User $user): void
    {
        if (! $this->access->canSeeRedNames($user)) {
            throw new AccessDeniedHttpException('Qizil toifadagi ismlarni ko‘rishga ruxsat yo‘q.');
        }
    }
}
