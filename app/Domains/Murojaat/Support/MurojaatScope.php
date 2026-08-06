<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Support;

/**
 * MurojAAT ko'rish qamrovi — rol + tuman (advisor MahallaScope naqshi).
 * Viloyat barcha tumanlarni ko'radi; admin/xodim/viewer FAQAT o'z tumanini.
 */
final class MurojaatScope
{
    public function __construct(
        public readonly ?string $role,
        public readonly ?string $districtId,
        public readonly ?string $profileId,
    ) {}

    public function isViloyat(): bool
    {
        return $this->role === 'murojaat_viloyat';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'murojaat_admin';
    }

    public function isXodim(): bool
    {
        return $this->role === 'murojaat_xodim';
    }

    public function isViewer(): bool
    {
        return $this->role === 'murojaat_viewer';
    }

    /** Barcha tumanlarni ko'ra oladimi (faqat viloyat)? */
    public function seesAllDistricts(): bool
    {
        return $this->isViloyat();
    }
}
