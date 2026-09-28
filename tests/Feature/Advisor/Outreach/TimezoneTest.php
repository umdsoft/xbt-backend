<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Services\StageMachine;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The app runs in UTC while the PostgreSQL session may run in another zone
 * (Asia/Tashkent locally). timestamptz values must store the real instant.
 */
class TimezoneTest extends OutreachTestCase
{
    public function test_timestamptz_columns_store_the_real_instant(): void
    {
        DB::connection('advisor')->statement("set local time zone 'Asia/Tashkent'");
        $user = $this->viloyat();
        $company = $this->company($user, ['stage' => Stage::SENT]);

        app(StageMachine::class)->move(Actor::ui($user), $company->id, Stage::REPLIED);

        $utc = DB::connection('advisor')
            ->selectOne("select to_char(stage_changed_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as u from outreach_companies where id = ?", [$company->id])->u;

        $this->assertLessThan(5, abs(Carbon::parse($utc, 'UTC')->diffInSeconds(now())));
        $this->assertLessThan(5, abs(Company::query()->find($company->id)->stage_changed_at->diffInSeconds(now())));
    }
}
