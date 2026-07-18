<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Hr\Models\Permission;
use App\Domains\Hr\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Multi-tenant rollar va ruxsatlar (HR domeni — `hr` ulanishi, `web` guard).
 * xbt loyihasidan (RoleAndPermissionSeeder) ko'chirildi.
 *
 * 3 tabaqa:
 *  - Cross-tenant (super-admin, viloyat-admin) — barcha hokimliklar
 *  - Tenant-level (tuman-admin) — bitta hokimlik to'liq boshqaruv
 *  - User-level (mutaxassis va boshqalar) — modulga qarab cheklangan
 *
 * Idempotent: Role/Permission::findOrCreate($name, 'web') — mavjudini topadi,
 * dubl yaratmaydi. givePermissionTo — takror bermaydi (sync, detach yo'q).
 */
class RolePermissionSeeder extends Seeder
{
    private const GUARD = 'web';

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ===== Permissionlar =====

        $permissions = [
            // Tenant-aware
            'tenant.view-all',           // Barcha tenantlarni ko'rish (cross-tenant)
            'tenant.switch',             // Tenant context'ni o'zgartirish (super-admin/viloyat-admin)
            'tenant.manage-users',       // O'z tenanti foydalanuvchilarini yaratish/o'zgartirish

            // Kadrlar moduli
            'kadrlar.view', 'kadrlar.create', 'kadrlar.update', 'kadrlar.delete', 'kadrlar.export',

            // Chora-tadbirlar moduli
            'tadbirlar.view', 'tadbirlar.create', 'tadbirlar.update', 'tadbirlar.delete',

            // Hokim yordamchilari moduli
            'hokim-yordamchilari.view',
            'hokim-yordamchilari.create',
            'hokim-yordamchilari.update',
            'hokim-yordamchilari.delete',

            // Yoshlar yetakchilari moduli
            'yoshlar.view',
            'yoshlar.create',
            'yoshlar.update',
            'yoshlar.delete',

            // Yoshlar uchrashuvlari moduli
            'meetings.view',
            'meetings.create',
            'meetings.update',
            'meetings.delete',

            // Murojaatlar moduli
            'appeals.view',
            'appeals.view-all',     // tenant ichida boshqa mahallani ham ko'rish
            'appeals.create',
            'appeals.update',
            'appeals.delete',
            'appeals.assign',       // routing/transfer
            'appeals.decide',       // qaror chiqarish (yettilik)
            'appeals.export',

            // Mahalla yettiligi moduli
            'councils.view',
            'councils.manage',      // yettilik tarkibini boshqarish

            // Tashkilotlar moduli (kotibyat mudiri yaratadi)
            'tashkilotlar.view',
            'tashkilotlar.create',
            'tashkilotlar.update',
            'tashkilotlar.delete',
            'tashkilot.manage-users',   // tashkilot admin/jamoa loginlarini ochish

            // Topshiriqlar (umumiy — tashkilotga biriktirish + org tomonidan ko'rish/hisobot)
            'topshiriqlar.assign-org',  // topshiriqni tashkilotga biriktirish
            'topshiriqlar.view-own',    // tashkilot o'ziga kelgan topshiriqlarni ko'radi
            'topshiriqlar.report',      // tashkilot ijro hisobotini kiritadi

            // AI moduli
            'ai.use',               // AI taklif olish
            'ai.review',            // AI natijalarini ko'rib chiqish (audit)

            // Tizim
            'user.view', 'user.create', 'user.update', 'user.delete',
            'audit.view',
            'dashboard.view',
            'dashboard.cross-tenant', // Barcha tenantlar bo'yicha agregatsiya
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }

        // Yangi yaratilgan ruxsatlar keshda ko'rinishi uchun (fresh deploy'da
        // givePermissionTo stale keshdan qidirib "not found" bermasligi uchun).
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ===== Rollar =====

        // 1. SUPER-ADMIN — Gate::before orqali avtomatik barcha permissionlar
        Role::findOrCreate('super-admin', self::GUARD);

        // 2. VILOYAT-ADMIN — barcha tenantlarni ko'radi, viloyatda to'liq boshqaruv
        $viloyat = Role::findOrCreate('viloyat-admin', self::GUARD);
        $viloyat->givePermissionTo([
            'tenant.view-all', 'tenant.switch', 'tenant.manage-users',
            'kadrlar.view', 'kadrlar.create', 'kadrlar.update', 'kadrlar.delete', 'kadrlar.export',
            'tadbirlar.view', 'tadbirlar.create', 'tadbirlar.update', 'tadbirlar.delete',
            'hokim-yordamchilari.view',
            'yoshlar.view',
            'meetings.view', 'meetings.create', 'meetings.update',
            'appeals.view', 'appeals.view-all', 'appeals.assign', 'appeals.export',
            'councils.view', 'councils.manage',
            'tashkilotlar.view',
            'ai.use', 'ai.review',
            'user.view', 'user.create', 'user.update', 'user.delete',
            'audit.view',
            'dashboard.view', 'dashboard.cross-tenant',
        ]);

        // 3. TUMAN-ADMIN — o'z hokimligida to'liq boshqaruv
        $tumanAdmin = Role::findOrCreate('tuman-admin', self::GUARD);
        $tumanAdmin->givePermissionTo([
            'tenant.manage-users',
            'kadrlar.view', 'kadrlar.create', 'kadrlar.update', 'kadrlar.delete', 'kadrlar.export',
            'tadbirlar.view', 'tadbirlar.create', 'tadbirlar.update', 'tadbirlar.delete',
            'hokim-yordamchilari.view', 'hokim-yordamchilari.create', 'hokim-yordamchilari.update', 'hokim-yordamchilari.delete',
            'yoshlar.view', 'yoshlar.create', 'yoshlar.update', 'yoshlar.delete',
            'meetings.view', 'meetings.create', 'meetings.update', 'meetings.delete',
            'appeals.view', 'appeals.view-all', 'appeals.create', 'appeals.update', 'appeals.assign', 'appeals.export',
            'councils.view', 'councils.manage',
            'tashkilotlar.view', 'tashkilotlar.create', 'tashkilotlar.update', 'tashkilotlar.delete',
            'tashkilot.manage-users', 'topshiriqlar.assign-org',
            'ai.use', 'ai.review',
            'user.view', 'user.create', 'user.update',
            'audit.view',
            'dashboard.view',
        ]);

        // 4. HOKIM MASLAHATCHISI — kuzatuv darajasida
        $hokimMaslahatchisi = Role::findOrCreate('hokim-maslahatchisi', self::GUARD);
        $hokimMaslahatchisi->givePermissionTo([
            'kadrlar.view',
            'tadbirlar.view',
            'hokim-yordamchilari.view',
            'yoshlar.view',
            'audit.view',
            'dashboard.view',
        ]);

        // 5. HOKIM O'RINBOSARI — chora-tadbir va hokim yordamchilari boshqaruv
        $hokimOrinbosari = Role::findOrCreate('hokim-orinbosari', self::GUARD);
        $hokimOrinbosari->givePermissionTo([
            'kadrlar.view',
            'tadbirlar.view', 'tadbirlar.create', 'tadbirlar.update',
            'hokim-yordamchilari.view', 'hokim-yordamchilari.create', 'hokim-yordamchilari.update',
            'yoshlar.view',
            'audit.view',
            'dashboard.view',
        ]);

        // 6. KOTIBIYAT MUDIRI — nazorat reja va topshiriqlarni boshqaradi,
        //    mavjud tashkilotlarga topshiriq biriktiradi.
        //    (Tashkilot YARATISH/boshqarish va Audit jurnal — admin vazifasi)
        $kotibyat = Role::findOrCreate('kotibyat-mudiri', self::GUARD);
        $kotibyat->givePermissionTo([
            'tadbirlar.view', 'tadbirlar.create', 'tadbirlar.update', 'tadbirlar.delete',
            'topshiriqlar.assign-org',
            'dashboard.view',
        ]);

        // 7. AXBOROT TAHLIL GURUHI
        $axborot = Role::findOrCreate('axborot-tahlil', self::GUARD);
        $axborot->givePermissionTo([
            'kadrlar.view',
            'tadbirlar.view',
            'hokim-yordamchilari.view',
            'yoshlar.view',
            'audit.view',
            'dashboard.view',
        ]);

        // 8. MUTAXASSIS — chora-tadbir va yoshlar bo'yicha ish
        $mutaxassis = Role::findOrCreate('mutaxassis', self::GUARD);
        $mutaxassis->givePermissionTo([
            'tadbirlar.view', 'tadbirlar.create', 'tadbirlar.update',
            'yoshlar.view', 'yoshlar.create', 'yoshlar.update',
            'dashboard.view',
        ]);

        // 9. KADRLAR XODIMI
        $kadrlar = Role::findOrCreate('kadrlar-xodimi', self::GUARD);
        $kadrlar->givePermissionTo([
            'kadrlar.view', 'kadrlar.create', 'kadrlar.update', 'kadrlar.delete', 'kadrlar.export',
            'dashboard.view',
        ]);

        // 10. TUMAN MUTAXASSISI — yangi rol (yoshlar yetakchilari uchun masalan)
        $tumanMutaxassis = Role::findOrCreate('tuman-mutaxassis', self::GUARD);
        $tumanMutaxassis->givePermissionTo([
            'tadbirlar.view',
            'yoshlar.view', 'yoshlar.create', 'yoshlar.update',
            'meetings.view', 'meetings.create',
            'appeals.view', 'appeals.create', 'appeals.update',
            'ai.use',
            'dashboard.view',
        ]);

        // 11. MAHALLA YETTILIGI AʼZOSI — o'z mahallasi murojaatlarini ko'rish va qaror chiqarish
        $council = Role::findOrCreate('mahalla-yettiligi', self::GUARD);
        $council->givePermissionTo([
            'appeals.view',
            'appeals.update',
            'appeals.decide',
            'councils.view',
            'meetings.view',
            'ai.use',
            'dashboard.view',
        ]);

        // 12. TASHKILOT ADMINI — o'z tashkiloti ma'lumoti, jamoasi va kelgan topshiriqlar
        $tashkilotAdmin = Role::findOrCreate('tashkilot-admin', self::GUARD);
        $tashkilotAdmin->givePermissionTo([
            'tashkilot.manage-users',   // o'z tashkiloti jamoasini boshqarish
            'topshiriqlar.view-own',
            'topshiriqlar.report',
            'dashboard.view',
        ]);

        // 13. TASHKILOT XODIMI — o'ziga kelgan topshiriqlarni ko'rish va hisobot
        $tashkilotXodimi = Role::findOrCreate('tashkilot-xodimi', self::GUARD);
        $tashkilotXodimi->givePermissionTo([
            'topshiriqlar.view-own',
            'topshiriqlar.report',
            'dashboard.view',
        ]);
    }
}
