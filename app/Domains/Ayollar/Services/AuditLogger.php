<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use App\Domains\Ayollar\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * AMALLAR JURNALI.
 *
 * NEGA ALOHIDA SERVIS: jurnal yozish har joyda takrorlansa, biror joyda
 * unutiladi — va aynan o'sha joy keyin tekshirilishi kerak bo'ladi.
 * Bitta nuqta bo'lsa, «bu amal jurnalga tushadimi?» degan savolga
 * bir qarashda javob beriladi.
 *
 * NIMA YOZILADI: kim, qachon, nima qildi, qaysi yozuv ustida va NIMA
 * O'ZGARDI. Oxirgisi eng qimmatli: «anketa tahrirlandi» degan qator
 * tekshiruv uchun deyarli foydasiz, «toifa yashildan sariqqa o'tdi»
 * esa aniq savolga javob beradi.
 *
 * QIYMATLARNING O'ZI EMAS, O'ZGARISH FAKTI. Maxfiy maydonlar
 * (`answers`, PII) jurnalga XOM yozilmaydi — aks holda jurnal ikkinchi,
 * himoyalanmagan ma'lumot omboriga aylanardi.
 */
class AuditLogger
{
    /** Jurnalga XOM yozilmaydigan maydonlar. */
    private const REDACTED = ['answers', 'pinfl', 'passport', 'phone', 'signature_data', 'consent_signature'];

    /**
     * Amalni yozadi.
     *
     * @param  array<string, mixed>  $changes
     */
    public function log(
        ?User $user,
        string $action,
        string $entityType,
        ?string $entityId = null,
        array $changes = [],
        ?Request $request = null,
    ): void {
        AuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'changes' => $changes === [] ? null : $this->redact($changes),
            'ip' => $request?->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Ikki holat orasidagi FARQNI yozadi.
     *
     * Faqat o'zgargan maydonlar qoladi: butun yozuvni saqlash jurnalni
     * tez shishirib yuborardi va o'zgarishni topish uchun ikki qatorni
     * qo'lda solishtirish kerak bo'lardi.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function logChange(
        ?User $user,
        string $action,
        string $entityType,
        string $entityId,
        array $before,
        array $after,
        ?Request $request = null,
    ): void {
        $diff = [];

        foreach ($after as $key => $value) {
            $old = $before[$key] ?? null;

            if ($old !== $value) {
                $diff[$key] = ['dan' => $old, 'ga' => $value];
            }
        }

        if ($diff === []) {
            return;
        }

        $this->log($user, $action, $entityType, $entityId, $diff, $request);
    }

    /**
     * Maxfiy maydonlarni qiymatsiz qoldiradi.
     *
     * Kalit QOLADI («answers o'zgardi» ma'lumot beradi), qiymat esa
     * `[yashirilgan]` bilan almashtiriladi.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function redact(array $changes): array
    {
        $out = [];

        foreach ($changes as $key => $value) {
            $out[$key] = in_array($key, self::REDACTED, true) ? '[yashirilgan]' : $value;
        }

        return $out;
    }
}
