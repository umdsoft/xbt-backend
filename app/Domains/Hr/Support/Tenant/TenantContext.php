<?php

declare(strict_types=1);

namespace App\Domains\Hr\Support\Tenant;

/**
 * Joriy request uchun HR (KBT) tenant kontekstini saqlovchi scoped singleton.
 *
 * - Markaziy foydalanuvchining HR profilidan (hokimlik_id) o'qiladi.
 * - Super-admin / viloyat-admin global (NULL) bo'lishi mumkin.
 * - Super-admin uchun `?tenant=` orqali switch (middleware'da o'rnatiladi).
 *
 * SOLID-S: faqat tenant context'ni saqlash.
 */
class TenantContext
{
    private ?string $hokimlikId = null;

    private bool $isGlobal = false; // super-admin barcha tenantlarni ko'radi

    public function set(?string $hokimlikId, bool $isGlobal = false): void
    {
        $this->hokimlikId = $hokimlikId;
        $this->isGlobal = $isGlobal;
    }

    public function id(): ?string
    {
        return $this->hokimlikId;
    }

    public function isGlobal(): bool
    {
        return $this->isGlobal;
    }

    public function reset(): void
    {
        $this->hokimlikId = null;
        $this->isGlobal = false;
    }
}
