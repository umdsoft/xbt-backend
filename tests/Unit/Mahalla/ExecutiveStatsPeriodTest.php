<?php

declare(strict_types=1);

namespace Tests\Unit\Mahalla;

use App\Domains\Mahalla\Services\ExecutiveStats;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExecutiveStatsPeriodTest extends TestCase
{
    public function test_today_starts_at_tashkent_midnight_not_utc(): void
    {
        // Toshkent: 2026-07-20 00:30  ==  UTC: 2026-07-19 19:30
        Carbon::setTestNow(Carbon::parse('2026-07-19 19:30:00', 'UTC'));

        $period = app(ExecutiveStats::class)->period();

        $this->assertSame('2026-07-20', $period['today'], 'Toshkent sanasi olinishi kerak');
        $this->assertSame(
            '2026-07-19 19:00:00',
            $period['today_start_utc']->format('Y-m-d H:i:s'),
            'Toshkent yarim tuni = UTC 19:00',
        );

        Carbon::setTestNow();
    }

    public function test_week_starts_on_monday(): void
    {
        // 2026-07-23 — payshanba (Toshkent)
        Carbon::setTestNow(Carbon::parse('2026-07-23 10:00:00', 'Asia/Tashkent'));

        $period = app(ExecutiveStats::class)->period();

        $this->assertSame('2026-07-20', $period['week_start'], 'hafta dushanbadan boshlanadi');

        Carbon::setTestNow();
    }
}
