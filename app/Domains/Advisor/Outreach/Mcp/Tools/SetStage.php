<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Services\StageMachine;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_stage')]
#[Description('Move a lead to another pipeline stage. Allowed through MCP: found->verified (needs a verified active contact), verified->awaiting_approval (needs a draft message), awaiting_approval->verified, sent->replied, replied->meeting_booked, meeting_booked->replied, sent+ -> closed_declined, any open -> closed_unsubscribed, any -> blocked_sanctions. "sent", "meeting_done", "visit_or_mou", "resident_or_office" and reopening are human-only. On refusal the error lists the allowed next stages.')]
class SetStage extends OutreachTool
{
    public function handle(Request $request, StageMachine $stages): Response
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid'],
            'to' => ['required', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->write(function () use ($stages, $data): array {
            $company = $stages->move($this->actor(), $data['company_id'], $data['to'], $data['reason'] ?? null);

            return [
                'company' => $this->present()->companyRow($company->load('country')),
                'next_stages' => Stage::nextFor($company->stage, Stage::VIA_MCP),
            ];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->required(),
            'to' => $schema->string()->enum(Stage::all())->required(),
            'reason' => $schema->string()->description('Short note stored in the audit log.'),
        ];
    }
}
