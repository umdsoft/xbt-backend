<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Services\SanctionsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('mark_sanctions')]
#[Description('Record the result of screening a company against the OFAC SDN and EU consolidated sanctions lists. Only companies marked clear are ever mailed; unchecked ones wait. hit is IRREVERSIBLE and blocks the lead (blocked_sanctions); changing hit to clear is refused (reason irreversible). Always give the source: which lists, their date, and the matched entry for a hit. Re-checking a clear company is allowed and refreshes the check date.')]
class MarkSanctions extends OutreachTool
{
    public function handle(Request $request, SanctionsService $sanctions): Response
    {
        $data = $request->validate(['company_id' => ['required', 'uuid']] + SanctionsService::rules());

        return $this->write(function () use ($sanctions, $data): array {
            $company = $sanctions->mark($this->actor(), $data['company_id'], $data['status'], $data['source']);

            return [
                'company' => $this->present()->companyRow($company->load('country')),
                'sanctions_status' => $company->sanctions_status,
                'sanctions_checked_at' => $company->sanctions_checked_at?->toIso8601String(),
                'sanctions_source' => $company->sanctions_source,
            ];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->required(),
            'status' => $schema->string()->enum(SanctionsService::STATUSES)->required(),
            'source' => $schema->string()->min(3)->max(SanctionsService::SOURCE_MAX)->description('e.g. "OFAC SDN + EU consolidated list as of 2026-09-27: no match".')->required(),
        ];
    }
}
