<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Services\ContactService;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;

class ContactLimitTest extends OutreachTestCase
{
    private function upsert(Actor $actor, array $data): array
    {
        return app(ContactService::class)->upsert($actor, $data);
    }

    public function test_third_active_contact_is_rejected(): void
    {
        $user = $this->tuman();
        $company = $this->company($user);
        $actor = Actor::ui($user);

        $this->upsert($actor, ['company_id' => $company->id, 'full_name' => 'A', 'email' => 'a@x.test', 'email_status' => 'verified']);
        $this->upsert($actor, ['company_id' => $company->id, 'full_name' => 'B', 'email' => 'b@x.test']);

        try {
            $this->upsert($actor, ['company_id' => $company->id, 'full_name' => 'C', 'email' => 'c@x.test']);
            $this->fail('Expected contact_limit');
        } catch (RuleViolation $e) {
            $this->assertSame(RuleViolation::CONTACT_LIMIT, $e->reason);
        }

        $this->assertSame(2, $company->contacts()->count());
    }

    public function test_inactive_contacts_do_not_use_the_quota(): void
    {
        $user = $this->tuman();
        $company = $this->company($user);
        $actor = Actor::ui($user);

        $this->upsert($actor, ['company_id' => $company->id, 'full_name' => 'A', 'email' => 'a2@x.test']);
        $this->upsert($actor, ['company_id' => $company->id, 'full_name' => 'B', 'email' => 'b2@x.test', 'email_status' => 'invalid']);
        $this->upsert($actor, ['company_id' => $company->id, 'full_name' => 'C', 'email' => 'c2@x.test']);

        $this->assertSame(3, $company->contacts()->count());
        $this->assertSame(2, $company->contacts()->active()->count());
    }

    public function test_reactivating_a_contact_respects_the_quota(): void
    {
        $user = $this->tuman();
        $company = $this->company($user);
        $actor = Actor::ui($user);
        $this->upsert($actor, ['company_id' => $company->id, 'full_name' => 'A', 'email' => 'a3@x.test']);
        $this->upsert($actor, ['company_id' => $company->id, 'full_name' => 'B', 'email' => 'b3@x.test']);
        $this->upsert($actor, ['company_id' => $company->id, 'full_name' => 'C', 'email' => 'c3@x.test', 'email_status' => 'invalid']);

        $this->expectException(RuleViolation::class);
        $this->upsert($actor, ['company_id' => $company->id, 'full_name' => 'C', 'email' => 'c3@x.test', 'email_status' => 'verified']);
    }

    public function test_email_used_at_another_company_is_duplicate(): void
    {
        $user = $this->tuman();
        $first = $this->company($user);
        $second = $this->company($user);
        $this->upsert(Actor::ui($user), ['company_id' => $first->id, 'full_name' => 'A', 'email' => 'same@x.test']);

        try {
            $this->upsert(Actor::ui($user), ['company_id' => $second->id, 'full_name' => 'A', 'email' => 'SAME@x.test']);
            $this->fail('Expected duplicate');
        } catch (RuleViolation $e) {
            $this->assertSame(RuleViolation::DUPLICATE, $e->reason);
        }
    }

    public function test_verified_contact_raises_the_company_score(): void
    {
        $user = $this->tuman();
        $company = $this->company($user);

        $this->upsert(Actor::ui($user), ['company_id' => $company->id, 'full_name' => 'A', 'email' => 'v@x.test', 'email_status' => 'verified']);

        $this->assertSame(20, $company->fresh()->icp_score);
    }

    public function test_unsubscribe_is_one_way_cancels_drafts_and_closes_lead(): void
    {
        $user = $this->tuman();
        $company = $this->company($user);
        $actor = Actor::ui($user);
        $contact = $this->upsert($actor, ['company_id' => $company->id, 'full_name' => 'A', 'email' => 'u@x.test'])['contact'];
        $draft = $this->draft($contact);

        $this->upsert($actor, ['company_id' => $company->id, 'contact_id' => $contact->id, 'unsubscribed' => true]);

        $this->assertNotNull($contact->fresh()->unsubscribed_at);
        $this->assertSame(Message::CANCELLED, $draft->fresh()->status);
        $this->assertSame(Stage::CLOSED_UNSUBSCRIBED, $company->fresh()->stage);

        $this->expectException(RuleViolation::class);
        $this->upsert($actor, ['company_id' => $company->id, 'contact_id' => $contact->id, 'unsubscribed' => false]);
    }

    public function test_cannot_add_contact_to_another_advisors_lead(): void
    {
        $company = $this->company($this->tuman());

        try {
            $this->upsert(Actor::ui($this->tuman()), ['company_id' => $company->id, 'full_name' => 'A', 'email' => 'n@x.test']);
            $this->fail('Expected not_found');
        } catch (RuleViolation $e) {
            $this->assertSame(RuleViolation::NOT_FOUND, $e->reason);
            $this->assertSame(404, $e->httpStatus());
        }
    }
}
