<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Services\ContactService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('upsert_contact')]
#[Description('Create or update a decision maker of a lead, matched by email (or contact_id). At most 2 active contacts per company (reason contact_limit). An email used at another company returns reason duplicate. do_not_contact and unsubscribed can be set but never cleared; unsubscribed also cancels pending messages and closes the lead.')]
#[IsIdempotent]
class UpsertContact extends OutreachTool
{
    public function handle(Request $request, ContactService $contacts): Response
    {
        $data = $request->validate(ContactService::rules());

        return $this->write(function () use ($contacts, $data): array {
            $result = $contacts->upsert($this->actor(), $data);

            return ['created' => $result['created'], 'contact' => $this->present()->contact($result['contact'])];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('Lead id from upsert_company / get_pipeline.')->required(),
            'contact_id' => $schema->string()->description('Existing contact id, to update a contact that has no email.'),
            'full_name' => $schema->string()->description('Required when creating.'),
            'title' => $schema->string(),
            'role_type' => $schema->string()->enum(ContactService::ROLE_TYPES),
            'email' => $schema->string()->format('email'),
            'email_status' => $schema->string()->enum(ContactService::EMAIL_STATUSES)->description('Result of email verification; only "verified" lets the lead move to verified.'),
            'linkedin_url' => $schema->string()->format('uri'),
            'language' => $schema->string()->description('Preferred language, ISO 639-1.'),
            'do_not_contact' => $schema->boolean(),
            'unsubscribed' => $schema->boolean(),
        ];
    }
}
