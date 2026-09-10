<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Mahalla\Support\MahallaMatcher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ташқи манбалардаги маҳалла номларининг вариантлари.
 *
 * Statistika fayllari mahalla nomini bazadagidan boshqacha yozadi. Sabablari
 * turlicha: imlo tebranishi (ЁВГИР / ЁВҒУР), ko'plik qo'shimchasi (ШОЛИКОР /
 * ШОЛИКОРЛАР), qisqartma (Г.ГУЛОМ / ҒОФУР ҒУЛОМ), yoki nom rasman o'zgargan
 * (БАЙНАЛМИЛАЛ / ПАХТАКОР).
 *
 * `MahallaMatcher` unli tebranishini o'zi yechadi, qolgani shu ro'yxatda.
 *
 * QANDAY ANIQLANGAN: har tuman ichida fayldagi mos kelmagan nomlar soni
 * bazadagi "da'vo qilinmagan" mahallalar soniga TENG chiqdi — ya'ni bir-birga
 * aniq moslik. Bu shunchaki o'xshashlik foizidan ancha kuchli dalil: 82%
 * o'xshashlik xato juftlik berishi mumkin (АНЖИРЧИ / ТАНДИРЧИ), bo'sh o'rin
 * hisobi esa faqat mantiqan mumkin bo'lgan variantni qoldiradi.
 *
 * Idempotent: qayta yurgizish xavfsiz, mavjud yozuvni yangilaydi.
 */
class MahallaAliasSeeder extends Seeder
{
    /**
     * [mahalla SOATO, tashqi manbadagi nom, manba turi]
     *
     * `variant` — imlo boshqacha, nom o'sha.
     * `former`  — nom rasman o'zgargan, fayl eskisini ishlatadi.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const ALIASES = [
        // --- Шовот тумани ---
        ['1733230018', 'ЗАМАНДОШ МФЙ', 'variant'],
        ['1733230011', 'ИЖТИМОЯТ МФЙ', 'variant'],

        // --- Боғот тумани ---
        ['1733204037', 'О.Отажанов МФЙ', 'variant'],          // qisqartma

        // --- Гурлан тумани ---
        ['1733208032', 'БАЙНАЛМИЛАЛ МФЙ', 'former'],          // -> Пахтакор

        // --- Қўшкўпир тумани ---
        ['1733212031', 'ЁВГИР МФЙ', 'variant'],
        ['1733212034', 'МЕСИТ МФЙ', 'variant'],

        // --- Тупроққалъа тумани ---
        ['1733221001', 'САХОВАТ МФЙ', 'variant'],
        ['1733221013', 'ТУПРОККАЛЪА МАСКАНИ МФЙ', 'variant'],

        // --- Урганч тумани ---
        ['1733217002', 'АНЖИРЧИ МФЙ', 'variant'],             // ko'plik
        ['1733217026', 'КУМРАВОТ МФЙ', 'variant'],
        ['1733217020', 'КУНА ОВУЛ МФЙ', 'variant'],
        ['1733217055', 'ШОЛИКОР МФЙ', 'variant'],             // ko'plik
        ['1733217056', 'ШОХИДОН МФЙ', 'variant'],             // ko'plik

        // --- Урганч шаҳар ---
        ['1733401037', 'Ж.МАНГУБЕРДИ МФЙ', 'variant'],        // qisqartma
        ['1733401003', 'МАЪРИФАТЧИ МФЙ', 'variant'],

        // --- Хазорасп тумани ---
        ['1733220007', 'Г.ГУЛОМ МФЙ', 'variant'],             // qisqartma
        ['1733220034', 'Ж. МАНГУБЕРДИ МФЙ', 'variant'],       // qisqartma
        ['1733220026', 'КОВИНЧИ МФЙ', 'variant'],
        ['1733220042', 'СУЛАЙМОН КАЛЪСИ МФЙ', 'variant'],
        ['1733220024', 'ТЕМИРЧИ МАСКАНИ МФЙ', 'variant'],
        ['1733220035', 'ШУКУРОНА МФЙ', 'variant'],

        // --- Хива тумани ---
        ['1733226004', 'БУСТОН МФЙ', 'variant'],
        ['1733226030', 'ПАН МАКСИМ МФЙ', 'variant'],
        ['1733226018', 'ГЎЖА ГУЗАРИ МФЙ', 'former'],          // -> Индавак

        // --- Хива шаҳар ---
        ['1733406016', 'ДЎСТЛИК МФЙ', 'variant'],

        // --- Янгиариқ тумани ---
        ['1733233003', 'АНГИАРИК МФЙ', 'variant'],
        ['1733233034', 'САВГАН МФЙ', 'variant'],              // "шаҳарча" tushib qolgan
        ['1733233029', 'СОБУРЗОН МФЙ', 'variant'],            // "шаҳарча" tushib qolgan

        // --- Янгибозор тумани ---
        ['1733236004', 'ОЧА КАЛЪА МФЙ', 'variant'],
    ];

    public function run(): void
    {
        $master = DB::connection('master');
        $added = 0;
        $missing = [];

        foreach (self::ALIASES as [$soato, $name, $source]) {
            $mahallaId = $master->table('mahallas')->where('soato_code', $soato)->value('id');

            if ($mahallaId === null) {
                $missing[] = "{$soato} ({$name})";
                continue;
            }

            $master->table('mahalla_aliases')->updateOrInsert(
                ['mahalla_id' => $mahallaId, 'normalized' => MahallaMatcher::normalize($name)],
                [
                    'id' => (string) Str::uuid(),
                    'name_cyr' => $name,
                    'source' => $source,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $added++;
        }

        $this->command?->info("Номлар вариантлари: {$added} / ".count(self::ALIASES));

        if ($missing !== []) {
            $this->command?->warn('SOATO топилмади: '.implode(', ', $missing));
        }
    }
}
