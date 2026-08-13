<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Services;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * 8 bosqichli holat mashinasi — yagona joyda majburlanadigan qoidalar.
 *
 * Manba xlsx'da bosqichlar shunchaki bayroq edi: hech kim «tender e'lon
 * qilindi, lekin loyiha-smeta hujjatlari hali yo'q» kabi mumkin bo'lmagan
 * holatni to'xtatmasdi. Bu servis aynan shuni to'xtatadi.
 */
class StageService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Bosqich yakunlangan hisoblanadigan holatlar (keyingisiga o'tish uchun). */
    private const DONE = ['yakunlangan', 'talab_etilmaydi'];

    /** Faqat kompleks ekspertizada uchraydigan holatlar. */
    private const EXPERTISE_ONLY = ['talab_etilmaydi', 'etiroz_bilan_qaytarilgan'];

    /**
     * Bosqich holatini o'zgartiradi.
     *
     * @param  array<string, mixed>  $payload  status, started_at, completed_at, note
     *
     * @throws ValidationException
     */
    public function update(ConstructionObject $object, string $stageCode, array $payload, User $user): ObjectStage
    {
        if (! in_array($stageCode, ConstructionObject::STAGES, true)) {
            abort(404, 'Бундай босқич йўқ.');
        }

        if ($object->lifecycle === 'qoralama') {
            throw ValidationException::withMessages([
                'stage' => 'Қоралама объектда босқич ўзгартириб бўлмайди. Аввал уни дастурга киритинг.',
            ]);
        }

        $status = (string) ($payload['status'] ?? '');
        if (! in_array($status, ObjectStage::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Нотўғри босқич ҳолати.']);
        }

        if (in_array($status, self::EXPERTISE_ONLY, true) && $stageCode !== 'complex_expertise') {
            throw ValidationException::withMessages([
                'status' => "«{$status}» ҳолати фақат комплекс экспертиза босқичида бўлади.",
            ]);
        }

        if (in_array($status, ['jarayonda', 'yakunlangan'], true)) {
            $blocker = $this->firstUnfinishedPredecessor($object, $stageCode);
            if ($blocker !== null) {
                throw ValidationException::withMessages([
                    'status' => "Аввалги босқич тугалланмаган: «{$blocker}». Босқичларни ўтказиб бўлмайди.",
                ]);
            }
        }

        $stage = ObjectStage::query()->firstOrNew([
            'object_id' => $object->id,
            'stage_code' => $stageCode,
        ]);
        $old = $stage->status ?? 'boshlanmagan';

        $stage->fill([
            'status' => $status,
            'started_at' => $payload['started_at'] ?? $stage->started_at,
            'completed_at' => $payload['completed_at'] ?? $stage->completed_at,
            'note' => $payload['note'] ?? $stage->note,
            'responsible_user_id' => $user->id,
        ])->save();

        $this->audit->log($object, $user, 'stage_change', $stageCode, $old, $status);
        $this->recalculate($object);

        return $stage->refresh();
    }

    /** Obyekt uchun 8 bosqich qatorini kafolatlaydi (yo'qlari `boshlanmagan`). */
    public function ensureStages(ConstructionObject $object): void
    {
        $existing = ObjectStage::query()->where('object_id', $object->id)->pluck('stage_code')->all();

        foreach (ConstructionObject::STAGES as $code) {
            if (! in_array($code, $existing, true)) {
                ObjectStage::query()->create([
                    'object_id' => $object->id,
                    'stage_code' => $code,
                    'status' => 'boshlanmagan',
                ]);
            }
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
            $status = $statuses[$code] ?? 'boshlanmagan';

            if ($status === 'jarayonda') {
                $current = $code;
            } elseif ($current === null && $status === 'boshlanmagan') {
                $current = $code;
            }

            if (! in_array($status, self::DONE, true)) {
                $allDone = false;
            }
        }

        $lifecycle = $object->lifecycle;
        if ($lifecycle !== 'qoralama' && $lifecycle !== 'toxtatilgan') {
            if ($allDone) {
                $lifecycle = 'tugallangan';
            } elseif ($current === ConstructionObject::STAGES[0] && ($statuses[$current] ?? '') === 'boshlanmagan') {
                $lifecycle = 'reja';
            } else {
                $lifecycle = 'jarayonda';
            }
        }

        $object->forceFill([
            'current_stage' => $current ?? 'handover',
            'lifecycle' => $lifecycle,
            'handover_done' => ($statuses['handover'] ?? '') === 'yakunlangan',
        ])->save();
    }

    /** Tugallanmagan birinchi oldingi bosqich nomi (yoki null). */
    private function firstUnfinishedPredecessor(ConstructionObject $object, string $stageCode): ?string
    {
        $statuses = ObjectStage::query()->where('object_id', $object->id)
            ->pluck('status', 'stage_code')->all();

        foreach (ConstructionObject::STAGES as $code) {
            if ($code === $stageCode) {
                return null;
            }
            if (! in_array($statuses[$code] ?? 'boshlanmagan', self::DONE, true)) {
                return $code;
            }
        }

        return null;
    }
}
