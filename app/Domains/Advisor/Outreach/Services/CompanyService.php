<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Country;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\Normalize;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Create/update leads (upsert by normalized domain), used by both the UI and
 * the MCP `upsert_company` tool.
 *
 * Rules enforced here, not in callers:
 *  - excluded or unknown country -> rejected;
 *  - a domain owned by another advisor -> duplicate, owner name only;
 *  - icp_score / tier are recomputed on every write, never taken from input;
 *  - stage and owner are not writable here (StageMachine / reassignOwner);
 *  - sanctions `hit` is irreversible and moves the lead to blocked_sanctions.
 */
final class CompanyService
{
    public const INDUSTRIES = [...IcpScorer::TARGET_INDUSTRIES, IcpScorer::GENERAL_INDUSTRY, 'other'];

    public const CLIENT_REGIONS = ['us', 'eu', 'uk', 'cis', 'mena', 'asia', 'other'];

    public const SANCTIONS = ['unchecked', 'clear', 'hit'];

    private const WRITABLE = [
        'name', 'country_code', 'region_city', 'employees', 'industry', 'has_offshore_center',
        'open_roles_6m', 'client_regions', 'languages', 'source',
        'export_contract_usd', 'parent_revenue_usd', 'sanctions_status',
    ];

    public function __construct(
        private readonly OutreachGate $gate,
        private readonly IcpScorer $scorer,
        private readonly StageMachine $stages,
        private readonly DedupeService $dedupe,
        private readonly AuditLogger $audit,
        private readonly AdvisorAccess $access,
    ) {}

    /**
     * Shape validation shared by the REST controller and the MCP tool.
     *
     * @return array<string, mixed>
     */
    public static function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255'],
            'country_code' => [$req, 'string', 'size:2'],
            'region_city' => ['nullable', 'string', 'max:120'],
            'employees' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'industry' => ['nullable', Rule::in(self::INDUSTRIES)],
            'has_offshore_center' => ['nullable', 'boolean'],
            'open_roles_6m' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'client_regions' => ['nullable', 'array', 'max:10'],
            'client_regions.*' => [Rule::in(self::CLIENT_REGIONS)],
            'languages' => ['nullable', 'array', 'max:10'],
            'languages.*' => ['string', 'regex:/^[a-z]{2}$/'],
            'source' => ['nullable', 'string', 'max:255'],
            'export_contract_usd' => ['nullable', 'integer', 'min:0'],
            'parent_revenue_usd' => ['nullable', 'integer', 'min:0'],
            'sanctions_status' => ['nullable', Rule::in(self::SANCTIONS)],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  already shape-validated
     * @return array{company: Company, created: bool}
     */
    public function upsert(Actor $actor, array $data): array
    {
        $this->gate->require($actor, OutreachGate::MANAGE);

        $domain = Normalize::domain((string) ($data['domain'] ?? ''));
        if ($domain === null) {
            throw new RuleViolation(RuleViolation::INVALID_DOMAIN, 'Домен нотўғри.');
        }

        $fields = array_intersect_key($data, array_flip(self::WRITABLE));
        if (isset($fields['country_code'])) {
            $fields['country_code'] = strtoupper((string) $fields['country_code']);
            $this->assertCountryAllowed($fields['country_code']);
        }

        try {
            return DB::connection('advisor')->transaction(
                fn (): array => $this->write($actor, $domain, $fields),
            );
        } catch (UniqueConstraintViolationException) {
            // Lost an insert race for the same domain: report it like any duplicate.
            $existing = Company::query()->where('domain', $domain)->firstOrFail();
            throw $this->duplicate($actor, $existing);
        }
    }

    /** viloyat only: hand a lead to another advisor. */
    public function reassignOwner(Actor $actor, string $companyId, string $newOwnerId): Company
    {
        if (! $this->gate->actorSeesAll($actor) || $actor->via !== Stage::VIA_UI) {
            throw new RuleViolation(RuleViolation::FORBIDDEN, 'Масъулни фақат вилоят маслаҳатчиси алмаштиради.');
        }

        $owner = User::query()->find($newOwnerId);
        if ($owner === null || ! $this->access->can($owner, OutreachGate::MANAGE)) {
            throw new RuleViolation(RuleViolation::NOT_FOUND, 'Бундай маслаҳатчи топилмади.');
        }

        return DB::connection('advisor')->transaction(function () use ($actor, $companyId, $newOwnerId): Company {
            $company = $this->gate->company($actor, $companyId, lock: true);
            $from = $company->owner_user_id;
            $company->forceFill(['owner_user_id' => $newOwnerId])->save();
            $this->audit->log($actor, 'company.owner_changed', 'company', $company->id, ['from' => $from, 'to' => $newOwnerId]);

            return $company;
        });
    }

    /** Recompute and persist the ICP score. Call inside the caller's transaction. */
    public function rescore(Company $company): Company
    {
        $result = $this->scorer->score($company, $company->contacts()->get());
        $company->forceFill(['icp_score' => $result['score'], 'tier' => $result['tier']]);
        if ($company->isDirty()) {
            $company->save();
        }

        return $company;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{company: Company, created: bool}
     */
    private function write(Actor $actor, string $domain, array $fields): array
    {
        $company = Company::query()->where('domain', $domain)->lockForUpdate()->first();

        if ($company !== null && ! $this->gate->canAccess($actor, $company)) {
            throw $this->duplicate($actor, $company);
        }

        if ($company === null) {
            foreach (['name', 'country_code'] as $required) {
                if (! isset($fields[$required])) {
                    throw new RuleViolation(RuleViolation::INVALID_INPUT, "«{$required}» майдони шарт.", ['field' => $required]);
                }
            }
            $company = new Company(['domain' => $domain, 'stage' => Stage::FOUND, 'sanctions_status' => 'unchecked']);
            $company->forceFill([
                'owner_user_id' => $actor->userId(),
                'created_by' => $actor->userId(),
                'created_via' => $actor->via,
                'stage_changed_at' => now(),
            ]);
        }

        $created = ! $company->exists;
        $wasHit = $company->sanctions_status === 'hit';

        if ($wasHit && isset($fields['sanctions_status']) && $fields['sanctions_status'] !== 'hit') {
            throw new RuleViolation(RuleViolation::IRREVERSIBLE, 'Санкция белгиси олиб ташланмайди.');
        }

        $company->fill($fields);
        $changes = $created ? [] : array_keys($company->getDirty());
        $company->save();

        $this->audit->log($actor, $created ? 'company.created' : 'company.updated', 'company', $company->id, $created
            ? ['domain' => $domain, 'country_code' => $company->country_code, 'name' => $company->name]
            : ['fields' => $changes]);

        if (! $wasHit && $company->sanctions_status === 'hit') {
            $this->stages->apply($actor, $company, Stage::BLOCKED_SANCTIONS, 'sanctions_hit');
        }

        return ['company' => $this->rescore($company), 'created' => $created];
    }

    private function assertCountryAllowed(string $code): void
    {
        $country = Country::query()->find($code);

        if ($country === null) {
            throw new RuleViolation(RuleViolation::COUNTRY_UNKNOWN, "«{$code}» мақсадли давлатлар рўйхатида йўқ.", ['country_code' => $code]);
        }

        if ($country->excluded) {
            throw new RuleViolation(
                RuleViolation::COUNTRY_EXCLUDED,
                "«{$country->name}» истисно қилинган давлат: {$country->excluded_reason}",
                ['country_code' => $code, 'excluded_reason' => $country->excluded_reason],
            );
        }
    }

    private function duplicate(Actor $actor, Company $company): RuleViolation
    {
        $info = $this->dedupe->describe($actor, $company);

        return new RuleViolation(
            RuleViolation::DUPLICATE,
            'Бу компания аллақачон бор (масъул: '.($info['owner_name'] ?? '—').').',
            $info,
        );
    }
}
