<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Database\Seeders;

use App\Domains\Advisor\Models\Project;
use App\Domains\Advisor\Models\ProjectUpdate;
use App\Domains\Advisor\Support\Period;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LOYIHALAR namuna seed — har tuman uchun HAR CHORAK 1 loyiha. 13 tuman × 4 chorak
 * = 52 loyiha (Loyihalar moduli/sahifasi uchun namunaviy portfel).
 *
 * Idempotent: (tuman, chorak) uchun loyiha allaqachon bo'lsa — o'tkazib yuboriladi.
 * Faqat PostgreSQL. AdvisorSeeder'dan (advisorlar yaratilgach) chaqiriladi.
 */
class AdvisorProjectSeeder extends Seeder
{
    /** Namuna loyiha sarlavhalari (SI/raqamli). Tuman×chorak bo'yicha aylanadi. */
    private const TITLES = [
        'Идоралараро электрон ҳужжат айланмасини «Ақлли ҳокимлик» доирасида кенгайтириш',
        'Аҳоли мурожаатларини таҳлил қилувчи сунъий интеллект ёрдамчисини жорий этиш',
        'Мактабда сунъий интеллект ва стартап хонасини ташкил этиш',
        'Кўча ёритилишини оптималлаштирувчи «Ақлли ёритиш» тизими',
        'Қишлоқ хўжалигида дрон ва СИ орқали ҳосилдорлик мониторинги',
        'Тиббий муассасаларда СИ асосидаги дастлабки ташхис тизими',
        'Коммунал хизматларда СИ орқали сарф-харажат таҳлили',
        'Жамоат хавфсизлиги учун видеоаналитика (компьютер кўриш) тизими',
        'Тадбиркорлар учун «бир дарча» рақамли хизматлар портали',
        'Аҳолини рақамли саводхонликка ўқитиш марказини ишга тушириш',
    ];

    /**
     * Choraklar: [period, planned_start, planned_end, actual_end|null, status, progress].
     * Q1/Q2 — bажарилган; Q3 — жараёнда; Q4 — режалаштирилган.
     *
     * @var array<int, array{0:string,1:string,2:string,3:?string,4:string,5:int}>
     */
    private const QUARTERS = [
        ['2026-Q1', '2026-01-20', '2026-03-15', '2026-03-25', 'done', 100],
        ['2026-Q2', '2026-04-18', '2026-06-20', '2026-06-28', 'done', 100],
        ['2026-Q3', '2026-07-15', '2026-09-25', null, 'in_progress', 55],
        ['2026-Q4', '2026-10-10', '2026-12-20', null, 'planned', 0],
    ];

    public function run(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $advisors = DB::connection('advisor')->table('advisors')
            ->where('level', 'tuman')
            ->whereNotNull('district_id')
            ->orderBy('created_at')
            ->get(['user_id', 'district_id']);

        foreach ($advisors as $di => $adv) {
            foreach (self::QUARTERS as $qi => [$period, $plannedStart, $plannedEnd, $actualEnd, $status, $progress]) {
                [$start, $end] = Period::range($period);

                // Idempotent: shu tuman+chorak uchun loyiha bormi?
                $exists = DB::connection('advisor')->table('projects')
                    ->where('district_id', $adv->district_id)
                    ->whereNull('deleted_at')
                    ->whereBetween('planned_start', [$start->toDateString(), $end->toDateString()])
                    ->exists();

                if ($exists) {
                    continue;
                }

                $title = self::TITLES[($di * 4 + $qi) % count(self::TITLES)];

                $project = Project::create([
                    'district_id' => $adv->district_id,
                    'title' => $title,
                    'description' => "«{$period}» чораги учун сунъий интеллект/рақамли ечимни жорий этиш тадбири (намунавий маълумот).",
                    'planned_start' => $plannedStart,
                    'planned_end' => $plannedEnd,
                    'actual_end' => $actualEnd,
                    'status' => $status,
                    'progress_percent' => $progress,
                    'created_by' => $adv->user_id,
                ]);

                $this->seedUpdates($project->id, (string) $adv->user_id, $status, $progress, $plannedStart);
            }
        }
    }

    /** done/in_progress loyihalarga progress tarixi qo'shadi. */
    private function seedUpdates(string $projectId, string $userId, string $status, int $progress, string $plannedStart): void
    {
        if ($status === 'planned') {
            return;
        }

        $base = Carbon::parse($plannedStart);
        // Faoliyat lentaси KELAJАК sanали bo'lmasin (occurred_at bugundan oshmасин).
        $cap = Carbon::today();

        $updates = [
            ['body' => 'Лойиҳа ишга туширилди, ишчи гуруҳ тузилди.', 'progress' => min(40, $progress), 'at' => $base->copy()->addDays(10)],
        ];

        if ($status === 'done') {
            $updates[] = ['body' => 'Лойиҳа якунланди, натижалар ҳужжатлаштирилди.', 'progress' => 100, 'at' => $base->copy()->addDays(50)];
        } else {
            $updates[] = ['body' => 'Жорий этиш жараёни давом этмоқда.', 'progress' => $progress, 'at' => $base->copy()->addDays(20)];
        }

        foreach ($updates as $u) {
            $at = $u['at']->greaterThan($cap) ? $cap->copy() : $u['at'];
            ProjectUpdate::create([
                'project_id' => $projectId,
                'user_id' => $userId,
                'body' => $u['body'],
                'progress_percent' => $u['progress'],
                'occurred_at' => $at,
            ]);
        }
    }
}
