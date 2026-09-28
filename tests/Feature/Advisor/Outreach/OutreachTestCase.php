<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Country;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Support\Stage;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Feature\Advisor\AdvisorTestCase;

/**
 * Shared helpers for outreach CRM tests. Countries use codes that are not real
 * ISO codes (X*), so they never collide with seeded data.
 */
abstract class OutreachTestCase extends AdvisorTestCase
{
    protected function viloyat(): User
    {
        return $this->makeAdvisor('advisor_viloyat', 'viloyat');
    }

    protected function tuman(): User
    {
        return $this->makeAdvisor('advisor_tuman', 'tuman', $this->someDistrictId());
    }

    protected function bolinma(): User
    {
        return $this->makeAdvisor('advisor_bolinma', 'bolinma');
    }

    protected function country(string $code = 'XA', bool $excluded = false): Country
    {
        return Country::query()->updateOrCreate(['code' => $code], [
            'name' => 'Test '.$code,
            'wave' => '1',
            'score' => 80,
            'excluded' => $excluded,
            'excluded_reason' => $excluded ? 'Test exclusion' : null,
            'default_language' => 'en',
        ]);
    }

    /** @param  array<string, mixed>  $attrs */
    protected function company(User $owner, array $attrs = []): Company
    {
        $country = $attrs['country_code'] ?? $this->country()->code;

        return Company::query()->create(array_merge([
            'name' => 'Acme '.Str::random(5),
            'domain' => Str::lower(Str::random(10)).'.test',
            'country_code' => $country,
            'owner_user_id' => $owner->id,
            'stage' => Stage::FOUND,
            'created_by' => $owner->id,
        ], $attrs));
    }

    /** @param  array<string, mixed>  $attrs */
    protected function contact(Company $company, array $attrs = []): Contact
    {
        return Contact::query()->create(array_merge([
            'company_id' => $company->id,
            'full_name' => 'Jane Doe',
            'role_type' => 'ceo',
            'email' => Str::lower(Str::random(8)).'@'.$company->domain,
            'email_status' => 'verified',
        ], $attrs));
    }

    /** @param  array<string, mixed>  $attrs */
    protected function draft(Contact $contact, array $attrs = []): Message
    {
        return Message::query()->create(array_merge([
            'contact_id' => $contact->id,
            'sequence_step' => 1,
            'language' => 'en',
            'subject' => 'Open a delivery center in Khorezm',
            'body' => 'Dear Jane, ...',
            'status' => Message::DRAFT,
        ], $attrs));
    }
}
