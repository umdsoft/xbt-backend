<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use App\Domains\Ayollar\Models\Balance;
use App\Domains\Ayollar\Models\BalanceSignature;
use App\Domains\Ayollar\Models\Metric;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Balansni yopish va ko'p imzoli tasdiqlash oqimi.
 *
 * TUMAN DARAJASIDA 8 IMZO (promt §5.1). Har idora FAQAT o'ziga tegishli
 * qatorlarni tasdiqlaydi — qaysi qator kimniki ekani `metric_registry.
 * owner_org_code` da. Ya'ni yangi tasdiqlovchi organ qo'shish KOD emas,
 * MA'LUMOT masalasi.
 *
 * 8-imzo (`economy_finance`) faqat oldingi 7 tasi qo'yilgandan keyin
 * ochiladi: yakuniy tasdiq — barcha tekshiruvlar o'tganining belgisi,
 * mustaqil fikr emas.
 */
class BalanceWorkflow
{
    public function __construct(private readonly BalanceCalculator $calculator) {}

    /**
     * Balansni yopadi.
     *
     * Tekshiruvdan o'tmasa BLOKLANADI va xato QAYSI QATORDA ekani
     * qaytariladi (promt §1.6). Noto'g'ri balans imzolangandan keyin uni
     * tuzatish ancha qimmat: imzolar bekor qilinishi, MFY qayta ishlashi
     * kerak bo'ladi.
     *
     * @return array<int, array<string, mixed>>  bo'sh = yopildi
     */
    public function close(Balance $balance, User $user): array
    {
        if (! $balance->isEditable()) {
            throw new RuntimeException('Balans allaqachon yopilgan.');
        }

        $errors = $this->calculator->verify($balance);

        if ($errors !== []) {
            return $errors;
        }

        DB::connection('ayollar')->transaction(function () use ($balance, $user): void {
            $balance->update([
                'status' => Balance::CLOSED,
                'closed_at' => now(),
                'closed_by' => $user->id,
                'return_reason' => null,
            ]);

            $this->openSignatureSlots($balance);
        });

        return [];
    }

    /**
     * Imzo qo'yadi.
     *
     * @throws RuntimeException
     */
    public function sign(Balance $balance, User $user, string $orgCode, ?string $comment = null): BalanceSignature
    {
        if ($balance->status === Balance::OPEN) {
            throw new RuntimeException('Balans hali yopilmagan — imzo qo‘yib bo‘lmaydi.');
        }

        $slot = BalanceSignature::query()
            ->where('balance_type', $balance->levelCode())
            ->where('balance_id', $balance->id)
            ->where('org_code', $orgCode)
            ->first();

        if ($slot === null) {
            throw new RuntimeException('Bu idora uchun imzo o‘rni ochilmagan.');
        }

        if ($slot->status === BalanceSignature::SIGNED) {
            throw new RuntimeException('Imzo allaqachon qo‘yilgan.');
        }

        if ($orgCode === BalanceSignature::FINAL_ORG && ! $this->readyForFinal($balance)) {
            throw new RuntimeException('Yakuniy tasdiq oldingi 7 imzodan keyin ochiladi.');
        }

        $slot->update([
            'user_id' => $user->id,
            'signed_at' => now(),
            'status' => BalanceSignature::SIGNED,
            'comment' => $comment,
        ]);

        // Barcha imzolar qo'yilsa — balans TASDIQLANDI.
        if ($this->allSigned($balance)) {
            $balance->update(['status' => Balance::APPROVED]);
        }

        return $slot;
    }

    /**
     * Balansni qaytaradi.
     *
     * Sabab MAJBURIY: «qaytarildi» degan xabar MFY faoliga nima
     * tuzatishni aytmaydi va u ikkinchi marta ham xuddi shu xato bilan
     * yuborardi.
     */
    public function returnBack(Balance $balance, User $user, string $reason): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Qaytarish sababi ko‘rsatilmagan.');
        }

        DB::connection('ayollar')->transaction(function () use ($balance, $user, $reason): void {
            $balance->update([
                'status' => Balance::RETURNED,
                'return_reason' => $reason,
                'closed_at' => null,
                'closed_by' => null,
            ]);

            // Imzolar BEKOR QILINADI. Qaytarilgan balans o'zgaradi, ya'ni
            // eski imzolar boshqa ma'lumotni tasdiqlagan bo'lib qolardi.
            BalanceSignature::query()
                ->where('balance_type', $balance->levelCode())
                ->where('balance_id', $balance->id)
                ->update([
                    'status' => BalanceSignature::PENDING,
                    'signed_at' => null,
                    'user_id' => null,
                ]);

            \App\Domains\Ayollar\Models\AuditLog::query()->create([
                'user_id' => $user->id,
                'action' => 'balance.returned',
                'entity_type' => $balance->levelCode().'_balance',
                'entity_id' => $balance->id,
                'changes' => ['reason' => $reason],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Imzo holati — tasdiqlash ekrani uchun.
     *
     * Har idora uchun: imzo qo'yilganmi, kim qo'ygan, va U QAYSI
     * QATORLARGA javobgar. Oxirgisi muhim: idora nimani tasdiqlayotganini
     * ko'rmasa, imzo rasmiyatchilikka aylanadi.
     *
     * @return array<int, array<string, mixed>>
     */
    public function signatureState(Balance $balance): array
    {
        $orgs = $balance->levelCode() === 'mahalla'
            ? BalanceSignature::MAHALLA_ORGS
            : BalanceSignature::DISTRICT_ORGS;

        $signatures = BalanceSignature::query()
            ->where('balance_type', $balance->levelCode())
            ->where('balance_id', $balance->id)
            ->get()
            ->keyBy('org_code');

        $ownedRows = Metric::query()
            ->whereNotNull('owner_org_code')
            ->get(['code', 'name_lat', 'owner_org_code'])
            ->groupBy('owner_org_code');

        $signedCount = $signatures->where('status', BalanceSignature::SIGNED)->count();

        return array_map(function (string $org) use ($signatures, $ownedRows, $signedCount, $balance): array {
            $sig = $signatures->get($org);
            $isFinal = $org === BalanceSignature::FINAL_ORG && $balance->levelCode() === 'district';

            return [
                'org_code' => $org,
                'status' => $sig?->status ?? BalanceSignature::PENDING,
                'signed_at' => $sig?->signed_at,
                'user_id' => $sig?->user_id,
                'comment' => $sig?->comment,
                // Yakuniy imzo qulfi — SPA tugmani o'chirib qo'yadi.
                'locked' => $isFinal && $signedCount < count(BalanceSignature::DISTRICT_ORGS) - 1,
                'rows' => $ownedRows->get($org, collect())->pluck('name_lat')->all(),
            ];
        }, $orgs);
    }

    // ---------------------------------------------------------------

    /** Yopilganda har idora uchun bo'sh imzo o'rni ochiladi. */
    private function openSignatureSlots(Balance $balance): void
    {
        $orgs = $balance->levelCode() === 'mahalla'
            ? BalanceSignature::MAHALLA_ORGS
            : BalanceSignature::DISTRICT_ORGS;

        foreach ($orgs as $org) {
            BalanceSignature::query()->firstOrCreate(
                [
                    'balance_type' => $balance->levelCode(),
                    'balance_id' => $balance->id,
                    'org_code' => $org,
                ],
                ['status' => BalanceSignature::PENDING],
            );
        }
    }

    private function readyForFinal(Balance $balance): bool
    {
        $required = count(BalanceSignature::DISTRICT_ORGS) - 1;

        $signed = BalanceSignature::query()
            ->where('balance_type', $balance->levelCode())
            ->where('balance_id', $balance->id)
            ->where('org_code', '<>', BalanceSignature::FINAL_ORG)
            ->where('status', BalanceSignature::SIGNED)
            ->count();

        return $signed >= $required;
    }

    private function allSigned(Balance $balance): bool
    {
        return ! BalanceSignature::query()
            ->where('balance_type', $balance->levelCode())
            ->where('balance_id', $balance->id)
            ->where('status', '<>', BalanceSignature::SIGNED)
            ->exists();
    }
}
