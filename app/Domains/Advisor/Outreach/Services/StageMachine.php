<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `companies.stage` (PLAN-crm.md §3).
 *
 * Channel rules come from Stage::allows(); this class adds the data
 * preconditions and the audit record.
 */
final class StageMachine
{
    public function __construct(
        private readonly OutreachGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    public function move(Actor $actor, string $companyId, string $to, ?string $reason = null): Company
    {
        $this->gate->require($actor, OutreachGate::MANAGE);

        return DB::connection('advisor')->transaction(function () use ($actor, $companyId, $to, $reason): Company {
            $company = $this->gate->company($actor, $companyId, lock: true);

            return $this->apply($actor, $company, $to, $reason);
        });
    }

    /**
     * Transition an already locked company. For services that change the stage
     * as a side effect inside their own transaction (sanctions hit, unsubscribe).
     */
    public function apply(Actor $actor, Company $company, string $to, ?string $reason = null): Company
    {
        $from = $company->stage;

        if (! in_array($to, Stage::all(), true) || ! Stage::allows($from, $to, $actor->via)) {
            throw new RuleViolation(
                RuleViolation::TRANSITION_NOT_ALLOWED,
                "«{$from}» → «{$to}» ўтиши бу канал орқали мумкин эмас.",
                ['from' => $from, 'to' => $to, 'via' => $actor->via, 'allowed' => Stage::nextFor($from, $actor->via)],
            );
        }

        $this->checkPreconditions($actor, $company, $from, $to);

        $company->forceFill(['stage' => $to, 'stage_changed_at' => now()])->save();

        $this->audit->log($actor, 'company.stage_changed', 'company', $company->id, array_filter([
            'from' => $from,
            'to' => $to,
            'reason' => $reason,
        ], static fn ($v): bool => $v !== null));

        return $company;
    }

    private function checkPreconditions(Actor $actor, Company $company, string $from, string $to): void
    {
        if ($from === Stage::CLOSED_DECLINED && ! $this->gate->actorSeesAll($actor)) {
            throw new RuleViolation(RuleViolation::FORBIDDEN, 'Рад этилган лидни фақат вилоят маслаҳатчиси қайта очади.');
        }

        if ($to === Stage::VERIFIED && $from === Stage::FOUND && ! $this->hasVerifiedContact($company)) {
            throw new RuleViolation(
                RuleViolation::TRANSITION_PRECONDITION,
                'Камида битта фаол, email тасдиқланган контакт керак.',
                ['requires' => 'verified_active_contact'],
            );
        }

        if ($to === Stage::AWAITING_APPROVAL && ! $this->hasDraft($company)) {
            throw new RuleViolation(
                RuleViolation::TRANSITION_PRECONDITION,
                'Тасдиққа юбориш учун камида битта хат қоралами керак.',
                ['requires' => 'draft_message'],
            );
        }
    }

    private function hasVerifiedContact(Company $company): bool
    {
        return Contact::query()->active()
            ->where('company_id', $company->id)
            ->where('email_status', 'verified')
            ->exists();
    }

    private function hasDraft(Company $company): bool
    {
        return Message::query()
            ->where('status', Message::DRAFT)
            ->whereIn('contact_id', Contact::query()->active()->where('company_id', $company->id)->select('id'))
            ->exists();
    }
}
