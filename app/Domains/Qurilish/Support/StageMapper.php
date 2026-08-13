<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Support;

use App\Domains\Qurilish\Models\ConstructionObject;

/**
 * Manbaning 40 ta bayroq-ustunini 8 ta (bosqich, holat) juftligiga aylantiradi.
 *
 * Excel holat mashinasini ustunlarga yoygan: har bosqichning har holati alohida
 * 0/1 ustun. Pivot uchun qulay, model uchun anti-naqsh. Bu sinf — ikkalasi
 * o'rtasidagi yagona ko'prik, shuning uchun qoidalar bir joyda va testlangan.
 *
 * Ustun harflari (spec 2.3):
 *   1 Loyihachini aniqlash : L e'lon, M e'lonsiz, N aniqlangan, O aniqlanmagan
 *   2 LSD                  : P ishlab chiqilgan, Q chiqilmagan, R jarayonda
 *   3 Shaharsozlik eksp.   : S kiritilgan, T xulosa, U ko'rilmoqda, V kiritilmagan
 *   4 Kompleks eksp.       : W talab, X kiritilgan, Y xulosa, Z ko'rilmoqda,
 *                            AA e'tiroz, AB kiritilmagan          [SHARTLI]
 *   5 Tender               : AD e'lon, AE aniqlangan, AF jarayon, AH e'lonsiz
 *   6 Shartnoma            : AI shartnoma soni
 *   7 Ijro                 : AK o'zlashtirilgan, AL %
 *   8 Topshirish           : AO reja, AP amalda
 */
class StageMapper
{
    /**
     * @param  array<string, mixed>  $f  CSV qatori (f_L, f_N, ... kalitlari bilan)
     * @return array<string, array{status: string}>
     */
    public function map(array $f): array
    {
        return [
            'designer_selection' => ['status' => $this->designerSelection($f)],
            'design_estimate' => ['status' => $this->designEstimate($f)],
            'urban_planning' => ['status' => $this->urbanPlanning($f)],
            'complex_expertise' => ['status' => $this->complexExpertise($f)],
            'tender' => ['status' => $this->tender($f)],
            'contract' => ['status' => $this->contract($f)],
            'execution' => ['status' => $this->execution($f)],
            'handover' => ['status' => $this->handover($f)],
        ];
    }

    /** Bosqichlar tartibda ekanini kafolatlaydi (voronka hisobi uchun). */
    public function orderedCodes(): array
    {
        return ConstructionObject::STAGES;
    }

    private function designerSelection(array $f): string
    {
        if ($this->on($f, 'f_N')) {
            return 'yakunlangan';
        }
        if ($this->on($f, 'f_L')) {
            return 'jarayonda';
        }

        return 'boshlanmagan';
    }

    private function designEstimate(array $f): string
    {
        if ($this->on($f, 'f_P')) {
            return 'yakunlangan';
        }
        if ($this->on($f, 'f_R')) {
            return 'jarayonda';
        }

        return 'boshlanmagan';
    }

    private function urbanPlanning(array $f): string
    {
        if ($this->on($f, 'f_T')) {
            return 'yakunlangan';
        }
        if ($this->on($f, 'f_U') || $this->on($f, 'f_S')) {
            return 'jarayonda';
        }

        return 'boshlanmagan';
    }

    /**
     * Shartli bosqich: `W` (талаб этилади) bo'sh bo'lsa, obyekt uchun kompleks
     * ekspertiza umuman kerak emas — 611 obyektdan 517 tasi shunday.
     */
    private function complexExpertise(array $f): string
    {
        if (! $this->on($f, 'f_W')) {
            return 'talab_etilmaydi';
        }
        if ($this->on($f, 'f_AA')) {
            return 'etiroz_bilan_qaytarilgan';
        }
        if ($this->on($f, 'f_Y')) {
            return 'yakunlangan';
        }
        if ($this->on($f, 'f_Z') || $this->on($f, 'f_X')) {
            return 'jarayonda';
        }

        return 'boshlanmagan';
    }

    private function tender(array $f): string
    {
        if ($this->on($f, 'f_AE')) {
            return 'yakunlangan';
        }
        if ($this->on($f, 'f_AF') || $this->on($f, 'f_AD')) {
            return 'jarayonda';
        }

        return 'boshlanmagan';
    }

    private function contract(array $f): string
    {
        return $this->num($f, 'contract_count') >= 1 ? 'yakunlangan' : 'boshlanmagan';
    }

    private function execution(array $f): string
    {
        if ($this->num($f, 'disbursed_pct') >= 100) {
            return 'yakunlangan';
        }
        if ($this->num($f, 'disbursed') > 0) {
            return 'jarayonda';
        }

        return 'boshlanmagan';
    }

    private function handover(array $f): string
    {
        return $this->on($f, 'handover_actual') ? 'yakunlangan' : 'boshlanmagan';
    }

    private function on(array $f, string $key): bool
    {
        return $this->num($f, $key) > 0;
    }

    private function num(array $f, string $key): float
    {
        $v = $f[$key] ?? null;

        return is_numeric($v) ? (float) $v : 0.0;
    }
}
