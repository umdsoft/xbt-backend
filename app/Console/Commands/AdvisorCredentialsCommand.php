<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Advisor\Services\AdvisorAdminService;
use Illuminate\Console\Command;

/**
 * Maslahatchilar login/parollari.
 *
 *   php artisan advisor:credentials            — ro'yxat (parolsiz, holat)
 *   php artisan advisor:credentials --reset    — HAR biriga YANGI parol generatsiya
 *                                                qilib, ro'yxatni ko'rsatadi (1 marta)
 *
 * --reset xavfli: mavjud parollar bekor bo'ladi. Ekranga chiqadi (logга EMAS) —
 * ro'yxatni xavfsiz joyга ko'chirib oling.
 */
class AdvisorCredentialsCommand extends Command
{
    protected $signature = 'advisor:credentials
        {--reset : Tuman maslahatchilariga yangi parol generatsiya qiladi (viloyat SAQLANADI)}
        {--include-viloyat : --reset bilan birga viloyat (super-admin) parolini HAM almashtiradi}
        {--force : Tasdiqsiz bajaradi (avtomatlashtirilган/SSH uchun)}';

    protected $description = 'Maslahatchilar login/parollari ro\'yxati (ixtiyoriy: parollarni reset qiladi)';

    public function handle(AdvisorAdminService $admin): int
    {
        if ($this->option('reset')) {
            $includeViloyat = (bool) $this->option('include-viloyat');
            $scope = $includeViloyat
                ? 'BARCHA maslahatchilar (viloyat super-admin HAM)'
                : 'TUMAN maslahatchilari (viloyat super-admin SAQLANADI)';

            if (! $this->option('force') && ! $this->confirm("{$scope} paroli yangilanadi (eski parollar bekor). Davom etilsinmi?", false)) {
                $this->warn('Bekor qilindi.');

                return self::SUCCESS;
            }

            $rows = $admin->resetAll($includeViloyat);
            $this->newLine();
            $this->info('Yangi parollar generatsiya qilindi ('.count($rows).' ta). Ushbu roʻyxatni xavfsiz saqlang — qayta koʻrsatilmaydi:');
            $this->table(
                ['Login', 'Ism', 'Daraja', 'Hudud', 'YANGI parol'],
                array_map(fn ($r) => [$r['login'], $r['name'], $r['level'], $r['district'] ?? '—', $r['password']], $rows),
            );

            return self::SUCCESS;
        }

        $rows = $admin->list();
        $this->table(
            ['Login', 'Ism', 'Daraja', 'Hudud', 'Faol', 'Oxirgi kirish'],
            array_map(fn ($r) => [
                $r['login'], $r['name'], $r['level'], $r['district']['name'] ?? '—',
                $r['active'] ? 'ha' : 'yoʻq', $r['last_login_at'] ?? '—',
            ], $rows),
        );
        $this->line('Parol generatsiya qilish: php artisan advisor:credentials --reset');

        return self::SUCCESS;
    }
}
