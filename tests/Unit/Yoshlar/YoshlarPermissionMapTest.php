<?php

declare(strict_types=1);

namespace Tests\Unit\Yoshlar;

use App\Domains\Yoshlar\Support\YoshlarAccess;
use PHPUnit\Framework\TestCase;

/**
 * Ruxsat xaritasi — spec 5-bo'limidagi matritsaning kod ko'rinishi.
 * Bu test o'zgarsa, spec ham o'zgarishi kerak (ataylab qattiq bog'langan).
 */
class YoshlarPermissionMapTest extends TestCase
{
    public function test_admin_cannot_verify(): void
    {
        $perms = $this->permissionsOf('yoshlar_admin');

        $this->assertContains('yoshlar.user.manage', $perms);
        $this->assertNotContains('yoshlar.youth.verify', $perms, 'Vakolatlar bo\'linishi buzildi');
    }

    public function test_hokim_orinbosari_cannot_reveal_pii(): void
    {
        $perms = $this->permissionsOf('yoshlar_hokim_orinbosari');

        $this->assertContains('yoshlar.view', $perms);
        $this->assertNotContains('yoshlar.pii.reveal', $perms);
        $this->assertNotContains('yoshlar.youth.update', $perms);
    }

    public function test_sektor_bolim_can_only_create(): void
    {
        $perms = $this->permissionsOf('sektor_bolim');

        $this->assertContains('yoshlar.youth.create', $perms);
        $this->assertNotContains('yoshlar.youth.update', $perms);
        $this->assertNotContains('yoshlar.pii.reveal', $perms);
    }

    public function test_no_wildcard_permission_exists(): void
    {
        foreach (YoshlarAccess::ROLES as $role) {
            $this->assertNotContains('*', $this->permissionsOf($role), "«{$role}» da wildcard bor");
        }
    }

    /** @return array<int, string> */
    private function permissionsOf(string $role): array
    {
        /** @var array<string, array<int, string>> $map */
        $map = (new \ReflectionClass(YoshlarAccess::class))->getConstant('PERMISSIONS');

        return $map[$role] ?? [];
    }
}
