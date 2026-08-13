<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Console\Commands;

use App\Domains\Qurilish\Models\Organization;
use App\Domains\Qurilish\Models\QurilishProfile;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * QURILISH domeni foydalanuvchisini yaratadi.
 *
 * NEGA buyruq: TZ bo'yicha hisoblar admin tomonidan beriladi — ochiq
 * ro'yxatdan o'tish yo'q. Bu ikkita yozuvni ATOMAR bog'lashni talab qiladi:
 * `auth.user_system_access` (rol) va `qurilish.profiles` (tashkilot doirasi).
 * Ikkinchisisiz buyurtmachi/boshqarma `QurilishScope` ning fail-closed
 * shoxiga tushadi va HECH NARSA ko'rmaydi — qo'lda SQL yozganda eng ko'p
 * qilinadigan xato shu. Buyruq shuni oldini oladi.
 *
 * Parol argument sifatida QABUL QILINMAYDI — u shell tarixiga tushmasin;
 * buyruq ichida generatsiya qilinadi va bir marta ko'rsatiladi.
 */
class MakeQurilishUserCommand extends Command
{
    protected $signature = 'qurilish:make-user
        {login : Кириш логини}
        {name : Тўлиқ исми}
        {role : Роль (qurilish_hokimlik|qurilish_prokuratura|qurilish_buyurtmachi|qurilish_boshqarma|qurilish_admin)}
        {--org= : Ташкилот номи ёки ID (буюртмачи/бошқарма учун МАЖБУРИЙ)}
        {--position= : Лавозими}';

    protected $description = 'Қурилиш тизими фойдаланувчисини яратади (роль + ташкилот доираси)';

    /** Bu rollar tashkilotga bog'lanmasa hech narsa ko'rmaydi (fail-closed scope). */
    private const NEEDS_ORG = ['qurilish_buyurtmachi', 'qurilish_boshqarma'];

    public function handle(): int
    {
        $login = trim((string) $this->argument('login'));
        $name = trim((string) $this->argument('name'));
        $role = trim((string) $this->argument('role'));

        if ($login === '' || $name === '') {
            $this->error('Логин ва исм бўш бўлиши мумкин эмас.');

            return self::FAILURE;
        }

        if (! in_array($role, QurilishAccess::ROLES, true)) {
            $this->error("«{$role}» — нотўғри роль. Мумкин: ".implode(', ', QurilishAccess::ROLES));

            return self::FAILURE;
        }

        // `withTrashed()` — `users.login` UNIQUE cheklovi soft-delete qatorlarni
        // ham hisobga oladi; tekshirmasak INSERT tushunarsiz xato bilan qulaydi.
        if (User::withTrashed()->where('login', $login)->exists()) {
            $this->error("«{$login}» логини аллақачон банд.");

            return self::FAILURE;
        }

        $orgId = $this->resolveOrganization($role);
        if ($orgId === false) {
            return self::FAILURE;
        }

        $systemId = DB::connection('auth')->table('systems')
            ->where('code', QurilishAccess::SYSTEM_CODE)->value('id');

        if ($systemId === null) {
            $this->error('«qurilish» тизими auth.systems жадвалида топилмади.');

            return self::FAILURE;
        }

        $password = Str::password(20);
        $userId = $this->createUser($login, $name, $password, $role, $systemId, $orgId);

        $this->info('Фойдаланувчи яратилди.');
        $this->line("  ID:         {$userId}");
        $this->line("  Логин:      {$login}");
        $this->line("  Роль:       {$role}");
        $this->line('  Ташкилот:   '.($orgId === null ? '— (вилоят даражаси)' : $orgId));
        $this->line("  Парол:      {$password}");
        $this->newLine();
        $this->warn('Парол БИР МАРТА кўрсатилди — хавфсиз жойга ёзиб қўйинг.');

        return self::SUCCESS;
    }

    /** @return string|null|false `false` — xato (buyruq to'xtatiladi). */
    private function resolveOrganization(string $role): string|null|false
    {
        $org = trim((string) ($this->option('org') ?? ''));

        if ($org === '') {
            if (in_array($role, self::NEEDS_ORG, true)) {
                $this->error("«{$role}» роли учун --org мажбурий: усиз фойдаланувчи ҳеч қандай объект кўрмайди.");

                return false;
            }

            return null;
        }

        $found = Organization::query()
            ->where('id', $org)
            ->orWhere('name_cyr', $org)
            ->orWhere('name_lat', $org)
            ->first();

        if ($found === null) {
            $this->error("«{$org}» ташкилоти топилмади. Аниқ номини ёки ID сини киритинг.");

            return false;
        }

        $this->line("Ташкилот: {$found->name_cyr}");

        return $found->id;
    }

    private function createUser(
        string $login,
        string $name,
        string $password,
        string $role,
        string $systemId,
        ?string $orgId,
    ): string {
        // Ikki ulanish (auth + qurilish) — bitta tranzaksiyada emas.
        // Shuning uchun tartib muhim: avval profil, keyin kirish huquqi.
        // Teskarisi bo'lganda profilsiz-u kira oladigan hisob qolib ketardi.
        $user = User::query()->create([
            'login' => $login,
            'name' => $name,
            'password' => $password, // 'hashed' cast — avtomatik hash
            'is_active' => true,
        ]);

        QurilishProfile::query()->create([
            'user_id' => $user->id,
            'role' => $role,
            'organization_id' => $orgId,
            'position' => $this->option('position') ?: null,
            'is_active' => true,
        ]);

        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'system_id' => $systemId,
            'role' => $role,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->id;
    }
}
