<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Support;

/**
 * advisor domeni ko'rish qamrovi — rol + tuman (mahalla MahallaScope naqshi).
 *
 * Viloyat/bo'linma barcha tumanlarni ko'radi; tuman FAQAT o'z tumanini.
 * Kontrollerlar/service shu obyektga qarab ma'lumotni filtrlaydi (IDOR himoyasi).
 */
final class AdvisorScope
{
    public function __construct(
        public readonly ?string $role,
        public readonly ?string $districtId,
        public readonly ?string $advisorId,
    ) {}

    public function isViloyat(): bool
    {
        return $this->role === 'advisor_viloyat';
    }

    public function isBolinma(): bool
    {
        return $this->role === 'advisor_bolinma';
    }

    public function isTuman(): bool
    {
        return $this->role === 'advisor_tuman';
    }

    /** Barcha tumanlarni ko'ra oladimi (viloyat + bo'linma)? */
    public function seesAllDistricts(): bool
    {
        return $this->isViloyat() || $this->isBolinma();
    }
}
