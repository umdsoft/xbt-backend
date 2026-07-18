<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Fresh-deploy seed tartibi (barcha seederlar IDEMPOTENT — `db:seed --force`
 * takror ishlatilsa dubl yaratmaydi):
 *
 *   1. SystemsSeeder      — auth.systems reyestri (xbt, mahalla) — SSO uchun majburiy
 *   2. RolePermissionSeeder — HR spatie rollar/ruxsatlar (web guard)
 *   3. SuperAdminSeeder   — boshlang'ich admin (auth.users + public.users + ruxsatlar + rol)
 *   4. MahallaPilotSeeder — pilot geo (master) + namunaviy honadonlar (mahalla)
 *
 * Tartib muhim: systems -> super-admin ruxsatlari; rollar -> super-admin roli;
 * (mahalla RBAC konsolidatsiyasi migratsiyasi ko'chalarni honadonlardan oladi,
 * shuning uchun MahallaPilotSeeder honadonlarni ham quradi).
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            SystemsSeeder::class,
            RolePermissionSeeder::class,
            SuperAdminSeeder::class,
            MahallaPilotSeeder::class,
        ]);
    }
}
