<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Services\OptOutService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('mark_unsubscribed')]
#[Description('Record that a contact asked not to be contacted again. IRREVERSIBLE: the contact is unsubscribed, pending letters are cancelled, the lead is closed as closed_unsubscribed and the email goes on the suppression list. Use it only when the person clearly asked to stop. Calling it again is a no-op (already=true).')]
#[IsIdempotent]
class MarkUnsubscribed extends OutreachTool
{
    public function handle(Request $request, OptOutService $optOut): Response
    {
        $data = $request->validate(['contact_id' => ['required', 'uuid']]);

        return $this->write(function () use ($optOut, $data): array {
            $result = $optOut->unsubscribe($this->actor(), $data['contact_id']);

            return [
                'already' => $result['already'],
                'suppressed' => $result['suppressed'],
                'contact' => $this->present()->contact($result['contact']),
            ];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'contact_id' => $schema->string()->required(),
        ];
    }
}
