<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Database\Seeders;

use App\Domains\Advisor\Models\Advisor;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ADVISOR poydevor seeder — 'advisor' tizimi (auth.systems) + 14 maslahatchi
 * (1 viloyat + 13 tuman, master.districts'ga bog'lab) + global super-admin'ga
 * advisor ruxsati (test uchun).
 *
 * Idempotent (mahalla/super-admin seeder naqshi): login bo'yicha firstOrCreate;
 * mavjud yozuvga tegilmaydi, dubl yaratilmaydi. Faqat PostgreSQL.
 *
 * Parollar KODDA EMAS — faqat env(ADVISOR_SEED_PASSWORD)'dan. Haqiqiy parollar
 * gitignored D:\kadr\xbt\.credentials.local.txt da hujjatlashtiriladi.
 */
class AdvisorSeeder extends Seeder
{
    /**
     * 13 tuman/shahar — master.districts.soato_code bo'yicha (barqaror kalit).
     * [soato_code => [login, ism (Kirill)]].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const DISTRICTS = [
        '1733401' => ['advisor_urganch_sh', 'Урганч шаҳар маслаҳатчиси'],
        '1733406' => ['advisor_xiva_sh', 'Хива шаҳар маслаҳатчиси'],
        '1733204' => ['advisor_bogot', 'Боғот тумани маслаҳатчиси'],
        '1733208' => ['advisor_gurlan', 'Гурлан тумани маслаҳатчиси'],
        '1733212' => ['advisor_qoshkopir', 'Қўшкўпир тумани маслаҳатчиси'],
        '1733230' => ['advisor_shovot', 'Шовот тумани маслаҳатчиси'],
        '1733221' => ['advisor_tuproqqala', 'Тупроққалъа тумани маслаҳатчиси'],
        '1733217' => ['advisor_urganch_t', 'Урганч тумани маслаҳатчиси'],
        '1733220' => ['advisor_xazorasp', 'Хазорасп тумани маслаҳатчиси'],
        '1733226' => ['advisor_xiva_t', 'Хива тумани маслаҳатчиси'],
        '1733223' => ['advisor_xonqa', 'Хонқа тумани маслаҳатчиси'],
        '1733233' => ['advisor_yangiariq', 'Янгиариқ тумани маслаҳатчиси'],
        '1733236' => ['advisor_yangibozor', 'Янгибозор тумани маслаҳатчиси'],
    ];

    public function run(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $password = $this->resolvePassword();

        // 1) 'advisor' tizimi reyestrda (SystemsSeeder ham qo'shadi — bu kafolat).
        $this->ensureSystem();

        // 1a) KPI katalogi (13 viloyat + 11 tuman) — yo'riqnoma IX (spec §7).
        $this->call(KpiCatalogSeeder::class);

        // 2) Viloyat maslahatchisi (district null).
        $this->ensureAdvisor('advisor_viloyat', 'Вилоят маслаҳатчиси', 'advisor_viloyat', 'viloyat', null, $password);

        // 3) 13 tuman/shahar maslahatchisi (master.districts'ga bog'lab).
        foreach (self::DISTRICTS as $soato => [$login, $name]) {
            $districtId = DB::connection('master')->table('districts')
                ->where('soato_code', $soato)->value('id');

            if ($districtId === null) {
                $this->command?->warn("Туман топилмади (SOATO {$soato}) — {$login} ўтказиб юборилди.");

                continue;
            }

            $this->ensureAdvisor($login, $name, 'advisor_tuman', 'tuman', (string) $districtId, $password);
        }

        // 4) Test super-admin — mavjud global 'admin' ga advisor_viloyat ruxsati +
        //    viloyat advisor profili (SSO: bitta admin barcha tizimni ko'radi).
        $adminId = DB::connection('auth')->table('users')->where('login', 'admin')->value('id');
        if ($adminId !== null) {
            $this->grantAccess((string) $adminId, 'advisor_viloyat');
            Advisor::firstOrCreate(
                ['user_id' => (string) $adminId],
                ['level' => 'viloyat', 'district_id' => null, 'position' => 'Супер администратор', 'active' => true],
            );
        }

        // 5) Loyihalar namuna (har tuman × har chorak 1 loyiha) + KPI target/derive —
        //    tuman advisorlari mavjud bo'lgach.
        $this->call(AdvisorProjectSeeder::class);

        // 6) Chora-tadbirlar (2026-yil yillik reja, namunадан 12 band).
        $this->call(ActionPlanSeeder::class);

        // 7) KPI real ma'lumot (Excel svodi: Reja Q1-Q4 + Bajarilish Q1/Q2) —
        //    KpiCatalogSeeder (1a) dan keyin, kpis to'ldirilgach.
        $this->call(KpiDataSeeder::class);
    }

    /**
     * Bitta maslahatchi: auth.users + user_system_access (rol) + advisor profili.
     * Barchasi idempotent (login/user_id bo'yicha).
     */
    private function ensureAdvisor(
        string $login,
        string $name,
        string $role,
        string $level,
        ?string $districtId,
        string $password,
    ): void {
        $user = User::firstOrCreate(
            ['login' => $login],
            ['name' => $name, 'password' => $password, 'is_active' => true],
        );

        $this->grantAccess($user->id, $role);

        Advisor::firstOrCreate(
            ['user_id' => $user->id],
            ['level' => $level, 'district_id' => $districtId, 'position' => $name, 'active' => true],
        );
    }

    /**
     * 'advisor' tizimini auth.systems'ga qo'shadi (mavjud bo'lsa — no-op).
     */
    private function ensureSystem(): void
    {
        $auth = DB::connection('auth');

        if ($auth->table('systems')->where('code', AdvisorAccess::SYSTEM_CODE)->exists()) {
            return;
        }

        $auth->table('systems')->insert([
            'id' => (string) Str::uuid(),
            'code' => AdvisorAccess::SYSTEM_CODE,
            'name' => 'Ҳоким маслаҳатчилари платформаси',
            'url' => null,
            'is_active' => true,
            'sort_order' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Foydalanuvchiga advisor tizimi ruxsati (auth.user_system_access) — idempotent.
     */
    private function grantAccess(string $userId, string $role): void
    {
        $auth = DB::connection('auth');

        $systemId = $auth->table('systems')->where('code', AdvisorAccess::SYSTEM_CODE)->value('id');
        if ($systemId === null) {
            return;
        }

        $exists = $auth->table('user_system_access')
            ->where('user_id', $userId)
            ->where('system_id', $systemId)
            ->exists();

        if ($exists) {
            return;
        }

        $auth->table('user_system_access')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'system_id' => $systemId,
            'role' => $role,
            'is_active' => true,
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Seed paroli — faqat env(ADVISOR_SEED_PASSWORD). Kodga yozilmaydi.
     * Production'da env bo'lmasa seed to'xtaydi (super-admin seeder naqshi).
     */
    private function resolvePassword(): string
    {
        $password = (string) env('ADVISOR_SEED_PASSWORD', '');

        if ($password !== '') {
            return $password;
        }

        if (app()->environment('production')) {
            throw new RuntimeException(
                'ADVISOR_SEED_PASSWORD .env da o\'rnatilmagan. Production da parolni kodga yozib bo\'lmaydi.',
            );
        }

        $this->command?->warn('ADVISOR_SEED_PASSWORD topilmadi — dev uchun vaqtinchalik parol ishlatildi. Kirib darhol almashtiring!');

        return 'ChangeMe!'.bin2hex(random_bytes(4));
    }
}
