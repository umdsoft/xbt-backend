<?php

declare(strict_types=1);

namespace App\Domains\Hr\Support\Authorization;

use App\Domains\Hr\Models\HrProfile;

/**
 * Rollar iyerarxiyasi — privilegiya darajasini taqqoslash uchun.
 *
 * Foydalanuvchi boshqaruvida (UserController / UserPolicy):
 *  - Kim kimni boshqara oladi (o'zidan past darajadagini)
 *  - Qaysi rolni tayinlashga ruxsat bor (o'zidan qat'iy past)
 *
 * Bu privilegiya eskalatsiyasining oldini oladi: masalan tuman-admin (70)
 * o'ziga yoki boshqaga super-admin (100) rolini tayinlay olmaydi.
 */
final class RoleHierarchy
{
    /**
     * Rol → daraja. Katta son = yuqori privilegiya.
     *
     * @var array<string, int>
     */
    public const RANK = [
        'super-admin' => 100,
        'viloyat-admin' => 90,
        'tuman-admin' => 70,
        'hokim-orinbosari' => 50,
        'kotibyat-mudiri' => 45,
        'hokim-maslahatchisi' => 40,
        'axborot-tahlil' => 40,
        'kadrlar-xodimi' => 30,
        'tuman-mutaxassis' => 30,
        'mutaxassis' => 20,
        'mahalla-yettiligi' => 20,
        'tashkilot-admin' => 15,
        'tashkilot-xodimi' => 10,
    ];

    /** Bitta rol nomining darajasi (noma'lum rol → 0). */
    public static function rankOf(string $role): int
    {
        return self::RANK[$role] ?? 0;
    }

    /** Foydalanuvchining eng yuqori rol darajasi. */
    public static function rank(HrProfile $user): int
    {
        $max = 0;
        foreach ($user->getRoleNames() as $role) {
            $max = max($max, self::rankOf((string) $role));
        }

        return $max;
    }

    /**
     * $actor $target foydalanuvchini boshqara oladimi (rol darajasi bo'yicha)?
     * super-admin — har doim; aks holda actor darajasi target darajasidan
     * QAT'IY yuqori bo'lishi kerak (teng darajadagilar bir-birini boshqarmaydi).
     */
    public static function canManage(HrProfile $actor, HrProfile $target): bool
    {
        if ($actor->hasRole('super-admin')) {
            return true;
        }

        return self::rank($actor) > self::rank($target);
    }

    /**
     * $actor $roleName rolini (kimgadir) tayinlay oladimi?
     * super-admin — istalganini; aks holda faqat o'zidan QAT'IY past darajani.
     */
    public static function canAssign(HrProfile $actor, string $roleName): bool
    {
        if ($actor->hasRole('super-admin')) {
            return true;
        }

        return self::rankOf($roleName) < self::rank($actor);
    }
}
