<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Records the result of an OFAC SDN / EU consolidated list check for a lead
 * (CLAUDE.md rule 4; owner decision 2026-09-27: only `clear` companies get
 * mail, `unchecked` ones wait in the send queue).
 *
 * The status change itself goes through CompanyService, which already makes
 * `hit` irreversible and moves the lead to blocked_sanctions. This class adds
 * who checked, when and against what (source note), and restricts marking to
 * viloyat advisors (MCP tokens exist only for viloyat).
 */
final class SanctionsService
{
    public const STATUSES = ['clear', 'hit'];

    public const SOURCE_MAX = 500;

    public function __construct(
        private readonly OutreachGate $gate,
        private readonly CompanyService $companies,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'status' => ['required', Rule::in(self::STATUSES)],
            'source' => ['required', 'string', 'min:3', 'max:'.self::SOURCE_MAX],
        ];
    }

    public function mark(Actor $actor, string $companyId, string $status, string $source): Company
    {
        $this->gate->require($actor, OutreachGate::MANAGE);
        if (! $this->gate->actorSeesAll($actor)) {
            throw new RuleViolation(RuleViolation::FORBIDDEN, 'Санкция натижасини фақат вилоят маслаҳатчиси белгилайди.');
        }
        if (! in_array($status, self::STATUSES, true)) {
            throw new RuleViolation(RuleViolation::INVALID_INPUT, 'Санкция ҳолати нотўғри.', ['allowed' => self::STATUSES]);
        }

        return DB::connection('advisor')->transaction(function () use ($actor, $companyId, $status, $source): Company {
            $company = $this->gate->company($actor, $companyId, lock: true);
            $previous = $company->sanctions_status;

            if ($previous === 'hit' && $status !== 'hit') {
                throw new RuleViolation(RuleViolation::IRREVERSIBLE, 'Санкция белгиси олиб ташланмайди.', ['from' => $previous, 'to' => $status]);
            }

            if ($previous !== $status) {
                $company = $this->companies->upsert($actor, ['domain' => $company->domain, 'sanctions_status' => $status])['company'];
            }

            $company->forceFill([
                'sanctions_checked_at' => now(),
                'sanctions_source' => mb_substr(trim($source), 0, self::SOURCE_MAX),
            ])->save();

            $this->audit->log($actor, 'company.sanctions_checked', 'company', $company->id, [
                'from' => $previous,
                'to' => $status,
                'source' => $company->sanctions_source,
            ]);

            return $company;
        });
    }
}
