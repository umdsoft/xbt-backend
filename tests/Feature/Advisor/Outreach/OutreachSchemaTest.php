<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Message;
use Illuminate\Database\QueryException;

class OutreachSchemaTest extends OutreachTestCase
{
    public function test_company_contact_and_message_round_trip(): void
    {
        $owner = $this->viloyat();
        $company = $this->company($owner, [
            'client_regions' => ['us', 'eu'],
            'languages' => ['en', 'ru'],
        ]);
        $contact = $this->contact($company);
        $message = $this->draft($contact);

        $fresh = Company::query()->with('contacts.messages', 'country')->findOrFail($company->id);

        $this->assertSame(['us', 'eu'], $fresh->client_regions);
        $this->assertSame('XA', $fresh->country->code);
        $this->assertSame('C', $fresh->tier);
        $this->assertSame('unchecked', $fresh->sanctions_status);
        $this->assertTrue($fresh->contacts->first()->isActive());
        $this->assertSame($message->id, $fresh->contacts->first()->messages->first()->id);
    }

    public function test_domain_is_unique(): void
    {
        $owner = $this->viloyat();
        $this->company($owner, ['domain' => 'dup.test']);

        $this->expectException(QueryException::class);
        $this->company($owner, ['domain' => 'dup.test']);
    }

    public function test_company_requires_known_country(): void
    {
        $this->expectException(QueryException::class);
        $this->company($this->viloyat(), ['country_code' => 'ZZ']);
    }

    public function test_message_hash_covers_subject_and_body(): void
    {
        $a = Message::hashOf('Subject', 'Body');

        $this->assertSame(64, strlen($a));
        $this->assertNotSame($a, Message::hashOf('Subject!', 'Body'));
        $this->assertNotSame($a, Message::hashOf('Subject', 'Body!'));
    }
}
