<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Console\Commands;

use App\Domains\Mahalla\Support\MahallaAccess;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `viloyat`/`tuman` (rahbariyat, faqat-ko'rish) rolli foydalanuvchi yaratish.
 *
 * NEGA alohida buyruq kerak: Admin UI (`UserManagementController::store()`)
 * `role` ustuniga qattiq `'deputat'` yozadi — u faqat operatsion (mahalla-5ligi)
 * userlarni boshqaradi. Demak prod'da `viloyat`/`tuman` akkaunti UI orqali
 * umuman yaratib bo'lmaydi, yagona yo'l — qo'lda SQL (xavfli: parol xatosi,
 * noto'g'ri rol, ikkilangan login, tumansiz `tuman` hisob). Bu buyruq shu
 * yo'lni xavfsiz, tekshiruvli muqobil bilan almashtiradi.
 *
 * Faqat KO'RUVCHI rollarni yaratadi: `MahallaAccess::VIEWER_ROLES` dan
 * `admin` ATAYLAB chiqarib tashlangan — admin boshqaruv roli, uni bu buyruq
 * orqali yaratish mumkin emas.
 *
 * `viloyat` uchun `MahallaProfile` (mahalla.users) shart emas — `MahallaAccess::
 * scopeFor()` viloyat uchun geo-profilga qaramay to'g'ridan-to'g'ri canSeeAll=true
 * qaytaradi (qarang: MahallaScope izohi). Shu sabab `viloyat` uchun bu buyruq
 * faqat `auth` ulanishiga yozadi, `mahalla` ulanishiga tegmaydi.
 *
 * `tuman` uchun esa `--district` MAJBURIY: profilida tuman ko'rsatilmagan
 * `tuman` user `MahallaAccess::scopeFor()`da fail-closed shoxiga tushadi va
 * HECH NARSA ko'rmaydi (qarang: shu sinf izohi). Ishlamaydigan hisob
 * tarqatishning oldini olish uchun buyruq buni bosh-dan rad etadi.
 */
class MakeViewerCommand extends Command
{
    protected $signature = 'mahalla:make-viewer
        {login : Кириш логини}
        {name : Тўлиқ исми}
        {--role=viloyat : Кўрувчи роли (viloyat|tuman)}
        {--district= : Туман UUID (фақат tuman учун МАЖБУРИЙ)}';

    protected $description = 'Раҳбарият (viloyat|tuman, фақат-кўриш) фойдаланувчисини яратади';

    /**
     * Bu buyruq orqali yaratish mumkin bo'lgan rollar — `MahallaAccess::
     * VIEWER_ROLES` dan `admin`siz. `admin` boshqaruv roli, ko'ruvchi emas.
     *
     * @var array<int, string>
     */
    private const CREATABLE_ROLES = ['viloyat', 'tuman'];

    public function handle(): int
    {
        $login = trim((string) $this->argument('login'));
        $name = trim((string) $this->argument('name'));
        $role = trim((string) $this->option('role'));
        $districtId = $this->normalizedDistrictOption();

        if ($login === '' || $name === '') {
            $this->error('Логин ва исм бўш бўлиши мумкин эмас.');

            return self::FAILURE;
        }

        if (! in_array($role, self::CREATABLE_ROLES, true)) {
            $this->error(
                "«{$role}» — нотўғри роль. Бу буйруқ фақат кўрувчи ролларини яратади: "
                .implode(', ', self::CREATABLE_ROLES).'.'
            );

            return self::FAILURE;
        }

        // Туман мажбурийлиги — дизайннинг асосий шарти: профилида туман
        // кўрсатилмаган `tuman` ҳисоб ҳеч нарса кўрмайди (fail-closed), шунинг
        // учун уни яратишга рухсат бериш — ишламайдиган ҳисоб тарқатиш.
        if ($role === 'tuman') {
            if ($districtId === null) {
                $this->error('«tuman» роли учун --district МАЖБУРИЙ.');

                return self::FAILURE;
            }

            if (! DB::connection('master')->table('districts')->where('id', $districtId)->exists()) {
                $this->error("«{$districtId}» тумани master.districts жадвалида топилмади.");

                return self::FAILURE;
            }
        }

        // `withTrashed()` — `users.login` ustunidagi UNIQUE cheklov soft-delete
        // qilingan qatorlarni ham hisobga oladi, shuning uchun bu yerda ham
        // tekshirmasak, keyingi INSERT bazadan tushunarsiz xato bilan qulaydi.
        if (User::withTrashed()->where('login', $login)->exists()) {
            $this->error("«{$login}» логини аллақачон банд — иккинчи марта яратилмади.");

            return self::FAILURE;
        }

        $systemId = DB::connection('auth')->table('systems')
            ->where('code', MahallaAccess::SYSTEM_CODE)->value('id');

        if ($systemId === null) {
            $this->error('«mahalla» тизими auth.systems жадвалида топилмади.');

            return self::FAILURE;
        }

        // Parol shu yerda, buyruq ICHIDA generatsiya qilinadi — argument
        // sifatida qabul qilinmaydi, shu bois shell tarixiga tushmaydi.
        $password = Str::password(20);

        $userId = DB::connection('auth')->transaction(
            fn () => $this->createUser($login, $name, $password, $role, $systemId, $districtId)
        );

        $this->info('Раҳбарият фойдаланувчиси яратилди.');
        $this->line("  ID:     {$userId}");
        $this->line("  Логин:  {$login}");
        $this->line("  Роль:   {$role}");
        if ($role === 'tuman') {
            $districtName = DB::connection('master')->table('districts')
                ->where('id', $districtId)->value('name_cyr');
            $this->line("  Туман:  {$districtName}");
        }
        $this->line("  Парол:  {$password}");
        $this->newLine();
        $this->warn('Парол фақат ҳозир, БИР МАРТА кўрсатилди — уни хавфсиз жойга ёзиб қўйинг.');

        return self::SUCCESS;
    }

    /**
     * `--district` qiymatini normallashtirish — bo'sh satr ham `null` deb olinadi.
     */
    private function normalizedDistrictOption(): ?string
    {
        $raw = $this->option('district');
        $trimmed = $raw !== null ? trim((string) $raw) : '';

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * `auth.users` + `auth.user_system_access` + (`tuman` bo'lsa) `mahalla.
     * users` profilini yaratadi.
     *
     * Bu TO'LIQ ATOMAR EMAS: `auth` va `mahalla` ikkita alohida ulanish,
     * ikkita alohida tranzaksiya bo'lgani uchun ikki baza aro haqiqiy
     * atomarlik Laravel'da mavjud emas. Ichki (`mahalla`) tranzaksiya
     * TASHQI (`auth`) dan OLDIN commit bo'ladi — agar shundan keyin tashqi
     * tranzaksiya (masalan keyingi bir amal) yiqilsa, `mahalla.users`da
     * yetim profil qolishi mumkin. Bu ZARARSIZ: `auth.users`da mos yozuv
     * bo'lmasa, hisob HECH QAERGA kira olmaydi — profil yolg'iz o'zi hech
     * narsaga ruxsat bermaydi (qarang: MahallaAccess::scopeFor()).
     */
    private function createUser(
        string $login,
        string $name,
        string $password,
        string $role,
        string $systemId,
        ?string $districtId,
    ): string {
        $user = User::query()->create([
            'login' => $login,
            'name' => $name,
            'password' => $password, // 'hashed' cast — avtomatik hash
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

        if ($role === 'tuman') {
            DB::connection('mahalla')->transaction(function () use ($user, $login, $name, $districtId) {
                DB::connection('mahalla')->table('users')->insert([
                    'id' => $user->id,
                    'name' => $name,
                    'login' => $login,
                    'password' => $user->password, // legacy NOT NULL ustun — auth hash nusxasi
                    'district_id' => $districtId,
                    'mahalla_id' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }

        return $user->id;
    }
}
