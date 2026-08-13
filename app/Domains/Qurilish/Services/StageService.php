<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Services;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bosqich holat mashinasi — XNP TZ v2.0 (2.3) moderatsiya sikli.
 *
 *   kutilmoqda → ochilgan → qoralama → tasdiqlash_kutilmoqda →
 *   korib_chiqilmoqda → tasdiqlangan (YOPIQ, keyingisi ochiladi)
 *                          ↘ rad_etilgan → qoralama
 *
 * NEGA MODERATSIYA KERAK: nazorat organi platformasida «kim yozsa, o'sha
 * ko'rinadi» tamoyili yaramaydi — tasdiqlanmagan foiz dashboardga chiqib
 * rahbariyatni chalg'itadi va buyurtmachi o'z ma'lumotini o'zi «yopa» oladi.
 * Shu sabab bosqich prokuratura tasdig'isiz YOPILMAYDI.
 *
 * Vakolatlar (TZ 11.1 matritsasi):
 *   to'ldirish / yuborish  — buyurtmachi (o'z obyekti), admin
 *   ko'rish / tasdiq / rad — prokuratura (moderator), admin
 */
class StageService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly QurilishAccess $access,
    ) {}

    /**
     * Ruxsat etilgan o'tishlar: joriy holat -> [yangi holatlar].
     * Bu yerda YO'Q o'tish — 422. Boshqa hech qayerda o'tish qilinmaydi.
     */
    private const TRANSITIONS = [
        'kutilmoqda' => ['ochilgan', 'talab_etilmaydi'],
        'ochilgan' => ['qoralama', 'talab_etilmaydi'],
        'qoralama' => ['tasdiqlash_kutilmoqda', 'talab_etilmaydi'],
        'tasdiqlash_kutilmoqda' => ['korib_chiqilmoqda', 'qoralama'],
        'korib_chiqilmoqda' => ['tasdiqlangan', 'rad_etilgan'],
        'rad_etilgan' => ['qoralama'],
        'tasdiqlangan' => [],           // yopiq — faqat admin qayta ocha oladi
        'talab_etilmaydi' => ['ochilgan'],
    ];

    // ---------- Buyurtmachi amallari ----------

    /** Qoralama saqlash: `ochilgan`/`rad_etilgan`/`qoralama` -> `qoralama`. */
    public function saveDraft(ConstructionObject $object, string $stageCode, array $payload, User $user): ObjectStage
    {
        $stage = $this->stage($object, $stageCode);
        $this->assertCan($user, 'qurilish.stage.update', 'Босқични тўлдириш ҳуқуқи йўқ.');
        $this->assertNotDraftObject($object);

        if (! in_array($stage->status, ObjectStage::EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Бу босқич ҳозир таҳрирланмайди (жорий ҳолат: '.$stage->status.').',
            ]);
        }

        if ($stage->status !== 'qoralama') {
            $this->transition($object, $stage, 'qoralama', $user);
        }

        $stage->fill([
            'malumot' => $payload['malumot'] ?? $stage->malumot,
            'started_at' => $payload['started_at'] ?? $stage->started_at ?? now()->toDateString(),
            'note' => $payload['note'] ?? $stage->note,
            'responsible_user_id' => $user->id,
        ])->save();

        return $stage->refresh();
    }

    /** Tasdiqqa yuborish: `qoralama` -> `tasdiqlash_kutilmoqda`. */
    public function submit(ConstructionObject $object, string $stageCode, User $user): ObjectStage
    {
        $stage = $this->stage($object, $stageCode);
        $this->assertCan($user, 'qurilish.stage.update', 'Босқични юбориш ҳуқуқи йўқ.');
        $this->assertNotDraftObject($object);
        $this->assertPredecessorsClosed($object, $stageCode);

        $this->transition($object, $stage, 'tasdiqlash_kutilmoqda', $user);

        $stage->forceFill([
            'submitted_at' => now(),
            'submitted_by' => $user->id,
            'rejection_reason' => null,
        ])->save();

        return $stage->refresh();
    }

    // ---------- Moderator amallari ----------

    /** Ko'rib chiqishni boshlash: `tasdiqlash_kutilmoqda` -> `korib_chiqilmoqda`. */
    public function startReview(ConstructionObject $object, string $stageCode, User $user): ObjectStage
    {
        $stage = $this->stage($object, $stageCode);
        $this->assertCan($user, 'qurilish.stage.moderate', 'Модерация ҳуқуқи йўқ.');

        $this->transition($object, $stage, 'korib_chiqilmoqda', $user);
        $stage->forceFill(['reviewed_by' => $user->id])->save();

        return $stage->refresh();
    }

    /** Tasdiqlash: `korib_chiqilmoqda` -> `tasdiqlangan`; keyingi bosqich ochiladi. */
    public function approve(ConstructionObject $object, string $stageCode, User $user): ObjectStage
    {
        $stage = $this->stage($object, $stageCode);
        $this->assertCan($user, 'qurilish.stage.moderate', 'Тасдиқлаш ҳуқуқи йўқ.');

        return DB::connection('qurilish')->transaction(function () use ($object, $stage, $stageCode, $user) {
            $this->transition($object, $stage, 'tasdiqlangan', $user);

            $stage->forceFill([
                'reviewed_at' => now(),
                'reviewed_by' => $user->id,
                'completed_at' => now()->toDateString(),
                'rejection_reason' => null,
            ])->save();

            $this->openNext($object, $stageCode, $user);
            $this->recalculate($object);

            return $stage->refresh();
        });
    }

    /** Rad etish: `korib_chiqilmoqda` -> `rad_etilgan`. Sabab MAJBURIY. */
    public function reject(ConstructionObject $object, string $stageCode, string $reason, User $user): ObjectStage
    {
        $stage = $this->stage($object, $stageCode);
        $this->assertCan($user, 'qurilish.stage.moderate', 'Рад этиш ҳуқуқи йўқ.');

        $reason = trim($reason);
        if ($reason === '') {
            // Sababsiz rad etish — buyurtmachi nimani tuzatishini bilmaydi.
            throw ValidationException::withMessages(['reason' => 'Рад этиш сабабини киритинг.']);
        }

        $this->transition($object, $stage, 'rad_etilgan', $user);

        $stage->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $user->id,
            'rejection_reason' => $reason,
        ])->save();

        $this->audit->log($object, $user, 'stage_reject', $stageCode, null, $reason);

        return $stage->refresh();
    }

    /** Rad etilgandan keyin tuzatishga qaytarish: `rad_etilgan` -> `qoralama`. */
    public function reopenDraft(ConstructionObject $object, string $stageCode, User $user): ObjectStage
    {
        $stage = $this->stage($object, $stageCode);
        $this->assertCan($user, 'qurilish.stage.update', 'Босқични қайта очиш ҳуқуқи йўқ.');

        $this->transition($object, $stage, 'qoralama', $user);

        return $stage->refresh();
    }

    /** «Талаб этилмайди» belgilash — faqat shartli bosqichlar uchun. */
    public function markNotRequired(ConstructionObject $object, string $stageCode, User $user): ObjectStage
    {
        if ($stageCode !== 'complex_expertise') {
            throw ValidationException::withMessages([
                'status' => '«Талаб этилмайди» фақат комплекс экспертиза босқичида белгиланади.',
            ]);
        }

        $stage = $this->stage($object, $stageCode);
        $this->assertCan($user, 'qurilish.stage.moderate', 'Ҳуқуқ йўқ.');

        return DB::connection('qurilish')->transaction(function () use ($object, $stage, $stageCode, $user) {
            $this->transition($object, $stage, 'talab_etilmaydi', $user);
            $this->openNext($object, $stageCode, $user);
            $this->recalculate($object);

            return $stage->refresh();
        });
    }

    // ---------- Yordamchi ----------

    /** Obyekt uchun 8 bosqich qatorini kafolatlaydi (birinchisi ochiq, qolgani kutmoqda). */
    public function ensureStages(ConstructionObject $object): void
    {
        $existing = ObjectStage::query()->where('object_id', $object->id)->pluck('stage_code')->all();

        foreach (ConstructionObject::STAGES as $i => $code) {
            if (in_array($code, $existing, true)) {
                continue;
            }

            ObjectStage::query()->create([
                'object_id' => $object->id,
                'stage_code' => $code,
                'tz_stage' => ObjectStage::TZ_STAGE[$code],
                // Strict sequential: faqat birinchi bosqich ochiq tug'iladi.
                'status' => $i === 0 ? 'ochilgan' : 'kutilmoqda',
            ]);
        }
    }

    /**
     * `current_stage` va `lifecycle` ni bosqichlardan qayta hisoblaydi.
     * Ikkalasi ham hosila qiymat — qo'lda qo'yilmaydi.
     */
    public function recalculate(ConstructionObject $object): void
    {
        $statuses = ObjectStage::query()->where('object_id', $object->id)
            ->pluck('status', 'stage_code')->all();

        $current = null;
        $allDone = true;

        foreach (ConstructionObject::STAGES as $code) {
            $status = $statuses[$code] ?? 'kutilmoqda';

            if (! in_array($status, ObjectStage::DONE_STATUSES, true)) {
                $allDone = false;
                if ($current === null) {
                    $current = $code;
                }
            }
        }

        $lifecycle = $object->lifecycle;
        if ($lifecycle !== 'qoralama' && $lifecycle !== 'toxtatilgan') {
            $lifecycle = $allDone ? 'tugallangan' : ($current === ConstructionObject::STAGES[0] ? 'reja' : 'jarayonda');
        }

        $object->forceFill([
            'current_stage' => $current ?? 'handover',
            'lifecycle' => $lifecycle,
            'handover_done' => ($statuses['handover'] ?? '') === 'tasdiqlangan',
        ])->save();
    }

    /** Moderator navbati: tasdiq kutayotgan bosqichlar soni. */
    public function pendingCount(): int
    {
        return ObjectStage::query()->whereIn('status', ObjectStage::PENDING_STATUSES)->count();
    }

    private function stage(ConstructionObject $object, string $stageCode): ObjectStage
    {
        if (! in_array($stageCode, ConstructionObject::STAGES, true)) {
            abort(404, 'Бундай босқич йўқ.');
        }

        $stage = ObjectStage::query()
            ->where('object_id', $object->id)->where('stage_code', $stageCode)->first();

        if ($stage === null) {
            $this->ensureStages($object);
            $stage = ObjectStage::query()
                ->where('object_id', $object->id)->where('stage_code', $stageCode)->firstOrFail();
        }

        return $stage;
    }

    /** Holat o'tishini tekshiradi va yozadi. Ruxsatsiz o'tish — 422. */
    private function transition(ConstructionObject $object, ObjectStage $stage, string $to, User $user): void
    {
        $from = $stage->status ?? 'kutilmoqda';

        if ($from === $to) {
            return;
        }

        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => "«{$from}» ҳолатидан «{$to}» ҳолатига ўтиб бўлмайди.",
            ]);
        }

        $stage->forceFill(['status' => $to])->save();
        $this->audit->log($object, $user, 'stage_change', $stage->stage_code, $from, $to);
    }

    /** Keyingi bosqichni ochadi (strict sequential zanjiri). */
    private function openNext(ConstructionObject $object, string $stageCode, User $user): void
    {
        $index = array_search($stageCode, ConstructionObject::STAGES, true);
        $next = ConstructionObject::STAGES[$index + 1] ?? null;

        if ($next === null) {
            return;
        }

        $nextStage = $this->stage($object, $next);
        if ($nextStage->status === 'kutilmoqda') {
            $this->transition($object, $nextStage, 'ochilgan', $user);
        }
    }

    /** Oldingi barcha bosqichlar yopiq bo'lishi shart (strict sequential). */
    private function assertPredecessorsClosed(ConstructionObject $object, string $stageCode): void
    {
        $statuses = ObjectStage::query()->where('object_id', $object->id)
            ->pluck('status', 'stage_code')->all();

        foreach (ConstructionObject::STAGES as $code) {
            if ($code === $stageCode) {
                return;
            }
            if (! in_array($statuses[$code] ?? 'kutilmoqda', ObjectStage::DONE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => "Аввалги босқич тугалланмаган: «{$code}». Босқичларни ўтказиб бўлмайди.",
                ]);
            }
        }
    }

    private function assertNotDraftObject(ConstructionObject $object): void
    {
        if ($object->lifecycle === 'qoralama') {
            throw ValidationException::withMessages([
                'stage' => 'Қоралама объектда босқич юритилмайди. Аввал уни давлат дастурига киритинг.',
            ]);
        }
    }

    private function assertCan(User $user, string $permission, string $message): void
    {
        if (! $this->access->can($user, $permission)) {
            abort(403, $message);
        }
    }
}
