<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Funnel metrics per country (and per advisor for viloyat), SPEC §6.
 *
 * found     all leads
 * verified  leads that passed research (stage >= verified, or declined later)
 * sent      leads with at least one sent/replied/bounced message
 * replied   leads with at least one reply
 * meetings  leads with a booked or held meeting
 * positive  leads with a reply classified `positive`
 */
final class StatsService
{
    private const VERIFIED_OR_LATER = [
        Stage::VERIFIED, Stage::AWAITING_APPROVAL, Stage::SENT, Stage::REPLIED, Stage::MEETING_BOOKED,
        Stage::MEETING_DONE, Stage::VISIT_OR_MOU, Stage::RESIDENT_OR_OFFICE, Stage::CLOSED_DECLINED,
    ];

    public function __construct(private readonly OutreachGate $gate) {}

    /** @return array<string, mixed> */
    public function summary(Actor $actor, ?string $wave = null): array
    {
        $byCountry = $this->grouped($actor, $wave, ['c.country_code', 'k.name', 'k.wave'])
            ->map(fn (object $r): array => [
                'country_code' => $r->country_code,
                'country_name' => $r->name,
                'wave' => $r->wave,
            ] + $this->row($r))->all();

        $result = [
            'by_country' => $byCountry,
            'totals' => $this->row($this->base($actor, $wave)->selectRaw($this->metricsSql())->first()),
        ];

        if ($this->gate->actorSeesAll($actor)) {
            $result['by_owner'] = $this->grouped($actor, $wave, ['c.owner_user_id'])
                ->map(fn (object $r): array => ['owner_user_id' => $r->owner_user_id] + $this->row($r))->all();
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $groupBy
     * @return Collection<int, object>
     */
    private function grouped(Actor $actor, ?string $wave, array $groupBy)
    {
        return $this->base($actor, $wave)
            ->select($groupBy)
            ->selectRaw($this->metricsSql())
            ->groupBy($groupBy)
            ->orderByDesc('found')
            ->get();
    }

    private function base(Actor $actor, ?string $wave): Builder
    {
        $q = DB::connection('advisor')->table('outreach_companies as c')
            ->join('outreach_countries as k', 'k.code', '=', 'c.country_code');

        if (! $this->gate->actorSeesAll($actor)) {
            $q->where('c.owner_user_id', $actor->userId());
        }
        if ($wave !== null && $wave !== '') {
            $q->where('k.wave', $wave);
        }

        return $q;
    }

    private function metricsSql(): string
    {
        $verified = "'".implode("','", self::VERIFIED_OR_LATER)."'";
        $messages = 'select 1 from outreach_messages m join outreach_contacts ct on ct.id = m.contact_id where ct.company_id = c.id';
        $replies = 'select 1 from outreach_replies r join outreach_messages m on m.id = r.message_id join outreach_contacts ct on ct.id = m.contact_id where ct.company_id = c.id';

        return implode(', ', [
            'count(*) as found',
            "count(*) filter (where c.stage in ({$verified})) as verified",
            "count(*) filter (where exists ({$messages} and m.status in ('sent','replied','bounced'))) as sent",
            "count(*) filter (where exists ({$replies})) as replied",
            "count(*) filter (where exists (select 1 from outreach_meetings mt where mt.company_id = c.id and mt.status in ('booked','done'))) as meetings",
            "count(*) filter (where exists ({$replies} and r.classification = 'positive')) as positive",
        ]);
    }

    /** @return array<string, int|float|null> */
    private function row(?object $r): array
    {
        $n = static fn (string $k): int => (int) ($r->{$k} ?? 0);
        $rate = static fn (int $part, int $whole): ?float => $whole > 0 ? round($part / $whole, 4) : null;

        return [
            'found' => $n('found'),
            'verified' => $n('verified'),
            'sent' => $n('sent'),
            'replied' => $n('replied'),
            'meetings' => $n('meetings'),
            'positive' => $n('positive'),
            'reply_rate' => $rate($n('replied'), $n('sent')),
            'meeting_rate' => $rate($n('meetings'), $n('sent')),
        ];
    }
}
