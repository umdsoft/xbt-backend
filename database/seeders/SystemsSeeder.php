<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Markaziy tizimlar reyestri (`auth.systems`) — SSO uchun majburiy.
 * `user_system_access.system_id` shu jadvalga bog'lanadi; reyestrsiz kirish
 * grant'lari (super-admin/deputat) null system_id bilan uziladi.
 *
 * Idempotent: `code` unique. Mavjud qator (dev) — o'zgartirilmaydi (nom/ID/holat
 * saqlanadi). Faqat yetishmayotgan tizim qo'shiladi → dev'da no-op.
 * Faqat PostgreSQL (auth schema).
 */
class SystemsSeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, name: string, sort_order: int}>
     */
    private const SYSTEMS = [
        ['code' => 'xbt', 'name' => 'Кадрлар бошқарув тизими (KBT)', 'sort_order' => 1],
        ['code' => 'mahalla', 'name' => 'Маҳалла мониторинги', 'sort_order' => 2],
        ['code' => 'advisor', 'name' => 'Ҳоким маслаҳатчилари платформаси', 'sort_order' => 3],
        ['code' => 'murojaat', 'name' => 'Мурожаатлар мониторинги', 'sort_order' => 4],
        ['code' => 'sport', 'name' => 'Спорт ва соғломлаштириш', 'sort_order' => 5],
        ['code' => 'qurilish', 'name' => 'Қурилиш дастурлари ижроси', 'sort_order' => 6],
    ];

    public function run(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $auth = DB::connection('auth');

        foreach (self::SYSTEMS as $system) {
            $exists = $auth->table('systems')->where('code', $system['code'])->exists();
            if ($exists) {
                continue; // Mavjud — nom/ID/holatga tegmaymiz (dev no-op).
            }

            $auth->table('systems')->insert([
                'id' => (string) Str::uuid(),
                'code' => $system['code'],
                'name' => $system['name'],
                'url' => null,
                'is_active' => true,
                'sort_order' => $system['sort_order'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
