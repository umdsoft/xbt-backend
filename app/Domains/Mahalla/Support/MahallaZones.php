<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Support;

/**
 * Honadon monitoring YADROSI: 4 nazorat zonasi va zona holati (ta'mir/ish jarayoni).
 *
 * Har honadon 4 mustaqil zonada kuzatiladi (har biri o'z holati, rasmi, o'zgarish
 * tarixi bilan). Holat o'tishi (masalan talab_qilinadi -> jarayonda) = O'ZGARISH;
 * rasm yuklash o'zi o'zgarish EMAS (u faqat dalil/kuzatuv).
 */
final class MahallaZones
{
    /** @var array<string, string> zona kodi => nomi (Kirill) */
    public const ZONES = [
        'facade' => 'Уй фасади',
        'kitchen' => 'Ошхона',
        'toilet' => 'Ҳожатхона',
        'yard' => 'Томорқа',
    ];

    /**
     * Zona holati — ta'mir/ish jarayoni modeli.
     * needs_work -> in_progress -> completed. `good` = dastlab yaxshi (ish shart emas).
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'needs_work' => 'Талаб қилинади',
        'in_progress' => 'Жараёнда',
        'completed' => 'Тугалланган',
        'good' => 'Яхши',
    ];

    /** Boshlang'ich holat (birinchi kuzatuvgacha). */
    public const DEFAULT_STATUS = 'needs_work';

    /**
     * Holat "og'irligi" — honadon umumiy holatini zonalaridan hisoblash uchun
     * (eng past = eng muammoli). Umumiy status = eng past zona statusi.
     *
     * @var array<string, int>
     */
    public const STATUS_RANK = [
        'needs_work' => 0,
        'in_progress' => 1,
        'good' => 2,
        'completed' => 3,
    ];

    /** @return array<int, string> */
    public static function zoneCodes(): array
    {
        return array_keys(self::ZONES);
    }

    /** @return array<int, string> */
    public static function statusCodes(): array
    {
        return array_keys(self::STATUSES);
    }

    /**
     * "Bajarilgan" deb hisoblanadigan zona holatlari (obodonlashtirish jamlanmasi
     * uchun yagona manba). `good` = dastlab yaxshi (ish shart emas — bajarilgan kabi).
     *
     * @return array<int, string>
     */
    public static function doneStatusCodes(): array
    {
        return ['completed', 'good'];
    }

    public static function isZone(string $code): bool
    {
        return array_key_exists($code, self::ZONES);
    }

    public static function isStatus(string $code): bool
    {
        return array_key_exists($code, self::STATUSES);
    }

    public static function zoneLabel(?string $code): ?string
    {
        return $code !== null ? (self::ZONES[$code] ?? null) : null;
    }

    public static function statusLabel(?string $code): ?string
    {
        return $code !== null ? (self::STATUSES[$code] ?? null) : null;
    }

    /**
     * Zonalar ro'yxati API/picker uchun: [['code'=>..,'name'=>..], ...].
     *
     * @return array<int, array{code: string, name: string}>
     */
    public static function zoneOptions(): array
    {
        $out = [];
        foreach (self::ZONES as $code => $name) {
            $out[] = ['code' => $code, 'name' => $name];
        }

        return $out;
    }

    /**
     * @return array<int, array{code: string, name: string}>
     */
    public static function statusOptions(): array
    {
        $out = [];
        foreach (self::STATUSES as $code => $name) {
            $out[] = ['code' => $code, 'name' => $name];
        }

        return $out;
    }

    /**
     * Zona statuslaridan honadon umumiy statusi (eng muammoli zona).
     *
     * @param  array<int, string>  $zoneStatuses
     */
    public static function overallStatus(array $zoneStatuses): string
    {
        if ($zoneStatuses === []) {
            return self::DEFAULT_STATUS;
        }

        $min = null;
        $minRank = PHP_INT_MAX;
        foreach ($zoneStatuses as $s) {
            $rank = self::STATUS_RANK[$s] ?? 0;
            if ($rank < $minRank) {
                $minRank = $rank;
                $min = $s;
            }
        }

        return $min ?? self::DEFAULT_STATUS;
    }
}
