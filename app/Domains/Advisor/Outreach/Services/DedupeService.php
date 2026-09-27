<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\Normalize;
use App\Models\User;

/**
 * "Is this lead already in the CRM?" (PLAN-crm.md §6).
 *
 * A match the actor may access comes back with ids and stage. A match owned by
 * another advisor comes back only as "exists, owned by <name>", so a tuman
 * advisor learns who to talk to without seeing the other advisor's lead.
 */
final class DedupeService
{
    public function __construct(private readonly OutreachGate $gate) {}

    /**
     * @return array{domain: ?string, email: ?string, matches: array<int, array<string, mixed>>}
     */
    public function check(Actor $actor, ?string $domain, ?string $email): array
    {
        $normDomain = $domain !== null && $domain !== '' ? Normalize::domain($domain) : null;
        $normEmail = $email !== null && $email !== '' ? Normalize::email($email) : null;

        $matches = [];

        if ($normDomain !== null) {
            $company = Company::query()->where('domain', $normDomain)->first();
            if ($company !== null) {
                $matches[] = ['match' => 'domain'] + $this->describe($actor, $company);
            }
        }

        if ($normEmail !== null) {
            $contact = Contact::query()->with('company')->where('email', $normEmail)->first();
            if ($contact !== null) {
                $matches[] = ['match' => 'email'] + $this->describe($actor, $contact->company, $contact);
            }
        }

        return ['domain' => $normDomain, 'email' => $normEmail, 'matches' => $matches];
    }

    /** @return array<string, mixed> */
    public function describe(Actor $actor, Company $company, ?Contact $contact = null): array
    {
        if (! $this->gate->canAccess($actor, $company)) {
            return ['accessible' => false, 'owner_name' => $this->ownerName($company)];
        }

        return array_filter([
            'accessible' => true,
            'company_id' => $company->id,
            'company_name' => $company->name,
            'stage' => $company->stage,
            'tier' => $company->tier,
            'owner_name' => $this->ownerName($company),
            'contact_id' => $contact?->id,
        ], static fn ($v): bool => $v !== null);
    }

    public function ownerName(Company $company): ?string
    {
        return $company->owner_user_id === null
            ? null
            : User::query()->whereKey($company->owner_user_id)->value('name');
    }
}
