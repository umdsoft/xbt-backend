<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Console\Commands;

use App\Domains\Yoshlar\Services\UserAdminService;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Yoshlar tizimi foydalanuvchisini yaratadi.
 *
 * Parol argument sifatida QABUL QILINMAYDI — shell tarixiga tushmasin;
 * buyruq ichida generatsiya qilinadi va bir marta ko'rsatiladi.
 */
class MakeYoshlarUserCommand extends Command
{
    protected $signature = 'yoshlar:make-user
        {login : Kirish logini}
        {name : To‘liq ismi}
        {role : Rol (yoshlar_hokim_orinbosari|yoshlar_admin|yoshlar_boshqarma|yoshlar_bolim|sektor_boshqarma|sektor_bolim)}
        {--org= : Tashkilot nomi yoki ID (tuman/sektor rollari uchun MAJBURIY)}
        {--position= : Lavozimi}
        {--can-patronage : Otaliq huquqi}';

    protected $description = 'Yoshlar tizimi foydalanuvchisini yaratadi (rol + tashkilot doirasi)';

    public function handle(UserAdminService $service): int
    {
        try {
            $result = $service->create(
                (string) $this->argument('login'),
                (string) $this->argument('name'),
                (string) $this->argument('role'),
                $this->option('org') === null ? null : (string) $this->option('org'),
                $this->option('position') === null ? null : (string) $this->option('position'),
                (bool) $this->option('can-patronage'),
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }
            $this->line('Mumkin bo‘lgan rollar: '.implode(', ', YoshlarAccess::ROLES));

            return self::FAILURE;
        }

        $this->info('Foydalanuvchi yaratildi.');
        $this->line('  ID:     '.$result['user_id']);
        $this->line('  Login:  '.$this->argument('login'));
        $this->line('  Rol:    '.$this->argument('role'));
        $this->line('  Parol:  '.$result['password']);
        $this->newLine();
        $this->warn('Parol BIR MARTA ko‘rsatildi — xavfsiz joyga yozib qo‘ying.');

        return self::SUCCESS;
    }
}
