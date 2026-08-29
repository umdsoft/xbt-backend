<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use App\Domains\Ayollar\Models\Anketa;
use App\Domains\Ayollar\Models\AnketaRedFlag;
use App\Domains\Ayollar\Models\Woman;
use Illuminate\Support\Facades\DB;

/**
 * Anketani saqlash — toifalash, qizil belgilar, ro'yxat raqami va QR
 * BIR TRANZAKSIYADA.
 *
 * NEGA BIRGA: agar anketa saqlanib, qizil belgilar yozilmay qolsa, balans
 * jimgina noto'g'ri bo'lardi — qizil son kam ko'rsatilardi va buni hech
 * qanday tekshiruv ushlamasdi (`yashil + sariq = jami` baribir bajarilardi).
 * Ya'ni bu yerdagi qisman muvaffaqiyat eng yomon holat.
 */
class AnketaService
{
    public function __construct(
        private readonly CategoryResolver $resolver,
        private readonly FormSchemaResolver $schema,
        private readonly RegNumberGenerator $regNumber,
        private readonly QrService $qr,
        private readonly BalanceRefresher $refresher,
    ) {}

    /**
     * Anketani yaratadi yoki yangilaydi.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $meta  device_id, gps_lat, gps_lng, client_uuid, status
     */
    public function save(
        Woman $woman,
        array $answers,
        array $meta,
        string $districtCode,
        string $mahallaCode,
        ?string $userId = null,
    ): Anketa {
        return DB::connection('ayollar')->transaction(function () use (
            $woman, $answers, $meta, $districtCode, $mahallaCode, $userId
        ): Anketa {
            $age = $this->resolver->ageAt($woman->birth_date);

            // Yoshga tegishli bo'lmagan javoblar TASHLANADI. Aks holda
            // tug'ilgan sana tuzatilganda eski javoblar zinapoyaga tushib,
            // 2 yoshli qiz «ishsiz» toifasiga o'tib ketardi.
            $answers = $this->schema->pruneAnswers($answers, $age);

            $resolution = $this->resolver->resolve($answers, $age);

            $anketa = $this->findExisting($woman, $meta);

            if ($anketa === null) {
                $anketa = new Anketa();
                $anketa->woman_id = $woman->id;
                $anketa->reg_number = $this->regNumber->next($districtCode, $mahallaCode);
                $anketa->qr_token = $this->qr->generateToken();
                $anketa->qr_hmac = $this->qr->sign($anketa->qr_token, $anketa->reg_number);
                $anketa->created_by = $userId;
            }

            $anketa->fill([
                'mahalla_id' => $woman->mahalla_id,
                'district_id' => $woman->district_id,
                'age_group' => $this->resolver->ageGroup($age),
                'answers' => $answers,
                'category' => $resolution->category,
                'balance_row' => $resolution->balanceRow,
                'resolution_trace' => $resolution->toArray()['trace'],
                'status' => $meta['status'] ?? Anketa::STATUS_COMPLETED,
                'device_id' => $meta['device_id'] ?? null,
                'gps_lat' => $meta['gps_lat'] ?? null,
                'gps_lng' => $meta['gps_lng'] ?? null,
                'client_uuid' => $meta['client_uuid'] ?? $anketa->client_uuid,
                'form_version' => $resolution->rulesVersion,
                'updated_by' => $userId,
            ]);

            $anketa->filled_at ??= now();
            $anketa->save();

            $this->syncRedFlags($anketa, $resolution);

            // INKREMENTAL yangilash — faqat shu MFY va uning yig'indilari.
            // Tranzaksiya ICHIDA: anketa saqlanib, balans yangilanmay
            // qolsa, panel eskirgan raqam ko'rsatardi va buni hech qanday
            // tekshiruv ushlamasdi.
            $this->refresher->afterAnketaChange(
                (string) $woman->mahalla_id,
                (string) $woman->district_id,
            );

            return $anketa;
        });
    }

    /**
     * Qizil belgilarni yangilaydi.
     *
     * TO'LIQ QAYTA YOZILADI, qo'shilmaydi: javob tuzatilib, belgi olib
     * tashlansa, eski qator qolib ketmasligi kerak. «Zo'ravonlik qurboni»
     * belgisi xato qo'yilgan bo'lsa, uni o'chirish MUMKIN bo'lishi shart.
     */
    private function syncRedFlags(Anketa $anketa, CategoryResolution $resolution): void
    {
        AnketaRedFlag::query()->where('anketa_id', $anketa->id)->delete();

        if ($resolution->redFlags === []) {
            return;
        }

        AnketaRedFlag::query()->insert(array_map(
            fn (array $flag): array => [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'anketa_id' => $anketa->id,
                'flag_code' => $flag['code'],
                'source_question' => $flag['source_question'],
                'created_at' => now(),
            ],
            $resolution->redFlags,
        ));
    }

    /**
     * Mavjud anketani topadi.
     *
     * `client_uuid` BIRINCHI tekshiriladi: offline navbat bir yozuvni bir
     * necha marta yuborishi mumkin (tarmoq uzilib, qayta urinish), va
     * ularning hammasi BITTA anketaga tushishi kerak — promt §11 dagi
     * idempotentlik talabi.
     *
     * @param  array<string, mixed>  $meta
     */
    private function findExisting(Woman $woman, array $meta): ?Anketa
    {
        $clientUuid = $meta['client_uuid'] ?? null;

        if ($clientUuid !== null) {
            $byClient = Anketa::query()->where('client_uuid', $clientUuid)->first();

            if ($byClient !== null) {
                return $byClient;
            }
        }

        return Anketa::query()
            ->where('woman_id', $woman->id)
            ->where('status', '<>', Anketa::STATUS_RETURNED)
            ->first();
    }

    /**
     * Toifani qayta hisoblaydi (qoida fayli yangilanganda).
     *
     * Yopilgan balansdagi anketalar TEGILMAYDI: imzolangan hisobot
     * ostidan raqam o'zgarmasligi kerak.
     */
    public function recalculate(Anketa $anketa): Anketa
    {
        if (! $anketa->isEditable()) {
            return $anketa;
        }

        $woman = $anketa->woman;

        if ($woman === null) {
            return $anketa;
        }

        $age = $this->resolver->ageAt($woman->birth_date);
        $resolution = $this->resolver->resolve($anketa->answers ?? [], $age);

        $anketa->update([
            'category' => $resolution->category,
            'balance_row' => $resolution->balanceRow,
            'resolution_trace' => $resolution->toArray()['trace'],
            'age_group' => $this->resolver->ageGroup($age),
            'form_version' => $resolution->rulesVersion,
        ]);

        $this->syncRedFlags($anketa, $resolution);

        $this->refresher->afterAnketaChange(
            (string) $anketa->mahalla_id,
            (string) $anketa->district_id,
        );

        return $anketa;
    }
}
