<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Database\Seeders;

use App\Domains\Murojaat\Models\MurojaatProfile;
use App\Domains\Murojaat\Support\MurojaatAccess;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * MUROJAAT poydevor seeder — 'murojaat' tizimi (auth.systems) + faza-1 Urganch tumani
 * foydalanuvchilari (viloyat + Urganch admin/xodim). Idempotent (login bo'yicha).
 * Parol KODDA EMAS — faqat env(MUROJAAT_SEED_PASSWORD). Faqat PostgreSQL.
 */
class MurojaatSeeder extends Seeder
{
    /** Urganch tumani — master.districts.soato_code. */
    private const URGANCH_TUMAN_SOATO = '1733217';

    public function run(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $password = $this->resolvePassword();
        $this->ensureSystem();

        // Viloyat (barcha tuman) — district null.
        $this->ensureUser('murojaat_viloyat', 'Вилоят мониторинг', 'murojaat_viloyat', 'viloyat', null, $password);

        // Urganch tumani (faza-1).
        $urganch = DB::connection('master')->table('districts')
            ->where('soato_code', self::URGANCH_TUMAN_SOATO)->value('id');
        if ($urganch !== null) {
            $this->ensureUser('murojaat_urganch', 'Урганч тумани — админ', 'murojaat_admin', 'tuman', (string) $urganch, $password);
            $this->ensureUser('murojaat_urganch_xodim', 'Урганч тумани — ходим', 'murojaat_xodim', 'tuman', (string) $urganch, $password);
        } else {
            $this->command?->warn('Urganch tumani topilmadi (SOATO '.self::URGANCH_TUMAN_SOATO.').');
        }
    }

    private function ensureUser(string $login, string $name, string $role, string $level, ?string $districtId, string $password): void
    {
        $user = User::firstOrCreate(
            ['login' => $login],
            ['name' => $name, 'password' => $password, 'is_active' => true],
        );
        $this->grantAccess($user->id, $role);
        MurojaatProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['level' => $level, 'district_id' => $districtId, 'position' => $name, 'active' => true],
        );
    }

    private function ensureSystem(): void
    {
        $auth = DB::connection('auth');
        if ($auth->table('systems')->where('code', MurojaatAccess::SYSTEM_CODE)->exists()) {
            return;
        }
        $auth->table('systems')->insert([
            'id' => (string) Str::uuid(),
            'code' => MurojaatAccess::SYSTEM_CODE,
            'name' => 'Мурожаатлар мониторинги',
            'url' => null,
            'is_active' => true,
            'sort_order' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function grantAccess(string $userId, string $role): void
    {
        $auth = DB::connection('auth');
        $systemId = $auth->table('systems')->where('code', MurojaatAccess::SYSTEM_CODE)->value('id');
        if ($systemId === null) {
            return;
        }
        if ($auth->table('user_system_access')->where('user_id', $userId)->where('system_id', $systemId)->exists()) {
            return;
        }
        $auth->table('user_system_access')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'system_id' => $systemId,
            'role' => $role, 'is_active' => true, 'granted_by' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function resolvePassword(): string
    {
        $password = (string) env('MUROJAAT_SEED_PASSWORD', '');
        if ($password !== '') {
            return $password;
        }
        if (app()->environment('production')) {
            throw new RuntimeException('MUROJAAT_SEED_PASSWORD .env da o\'rnatilmagan.');
        }
        $this->command?->warn('MUROJAAT_SEED_PASSWORD topilmadi — vaqtinchalik dev parol. Kirib almashtiring!');

        return 'ChangeMe!'.bin2hex(random_bytes(4));
    }
}
