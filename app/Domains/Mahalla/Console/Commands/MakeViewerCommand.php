<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Console\Commands;

use App\Domains\Mahalla\Support\MahallaAccess;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `viloyat` (rahbariyat, faqat-ko'rish) rolli foydalanuvchi yaratish.
 *
 * NEGA alohida buyruq kerak: Admin UI (`UserManagementController::store()`)
 * `role` ustuniga qattiq `'deputat'` yozadi — u faqat operatsion (mahalla-5ligi)
 * userlarni boshqaradi. Demak prod'da `viloyat` akkaunti UI orqali umuman
 * yaratib bo'lmaydi, yagona yo'l — qo'lda SQL (xavfli: parol xatosi, noto'g'ri
 * rol, ikkilangan login). Bu buyruq shu yo'lni xavfsiz, tekshiruvli muqobil
 * bilan almashtiradi.
 *
 * `viloyat` uchun `MahallaProfile` (mahalla.users) shart emas — `MahallaAccess::
 * scopeFor()` viloyat uchun geo-profilga qaramay to'g'ridan-to'g'ri canSeeAll=true
 * qaytaradi (qarang: MahallaScope izohi). Shu sabab bu buyruq faqat `auth`
 * ulanishiga yozadi, `mahalla` ulanishiga tegmaydi.
 */
class MakeViewerCommand extends Command
{
    protected $signature = 'mahalla:make-viewer {login : Кириш логини} {name : Тўлиқ исми}';

    protected $description = 'Раҳбарият (viloyat, фақат-кўриш) фойдаланувчисини яратади';

    public function handle(): int
    {
        $login = trim((string) $this->argument('login'));
        $name = trim((string) $this->argument('name'));

        if ($login === '' || $name === '') {
            $this->error('Логин ва исм бўш бўлиши мумкин эмас.');

            return self::FAILURE;
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

        $userId = DB::connection('auth')->transaction(function () use ($login, $name, $password, $systemId) {
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
                'role' => 'viloyat',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $user->id;
        });

        $this->info('Раҳбарият фойдаланувчиси яратилди.');
        $this->line("  ID:     {$userId}");
        $this->line("  Логин:  {$login}");
        $this->line("  Парол:  {$password}");
        $this->newLine();
        $this->warn('Парол фақат ҳозир, БИР МАРТА кўрсатилди — уни хавфсиз жойга ёзиб қўйинг.');

        return self::SUCCESS;
    }
}
