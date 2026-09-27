<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Services\CompanyService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('upsert_company')]
#[Description('Create or update a lead (foreign IT company), matched by normalized domain. Companies from excluded or non-target countries are rejected (reason country_excluded / country_unknown). A domain owned by another advisor returns reason duplicate with the owner name. ICP score and tier are computed by the server; stage and owner cannot be set here. Setting sanctions_status=hit blocks the lead permanently.')]
#[IsIdempotent]
class UpsertCompany extends OutreachTool
{
    public function handle(Request $request, CompanyService $companies): Response
    {
        $data = $request->validate(CompanyService::rules());

        return $this->write(function () use ($companies, $data): array {
            $result = $companies->upsert($this->actor(), $data);

            return [
                'created' => $result['created'],
                'company' => $this->present()->companyRow($result['company']->load('country')),
            ];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return self::properties($schema);
    }

    /**
     * Company fields, shared with upsert_companies.
     *
     * @return array<string, mixed>
     */
    public static function properties(JsonSchema $schema): array
    {
        return [
            'domain' => $schema->string()->description('Company website domain, e.g. acme.com (URL accepted, normalized).')->required(),
            'name' => $schema->string()->description('Company name. Required when creating.'),
            'country_code' => $schema->string()->description('ISO 3166-1 alpha-2 of the headquarters. Required when creating.'),
            'region_city' => $schema->string(),
            'employees' => $schema->integer()->min(0),
            'industry' => $schema->string()->enum(CompanyService::INDUSTRIES),
            'has_offshore_center' => $schema->boolean()->description('Already runs an offshore/nearshore delivery center.'),
            'open_roles_6m' => $schema->integer()->min(0)->description('Open engineering roles in the last 6 months.'),
            'client_regions' => $schema->array()->items($schema->string()->enum(CompanyService::CLIENT_REGIONS)),
            'languages' => $schema->array()->items($schema->string())->description('Working languages, ISO 639-1 (en, ru, tr...).'),
            'source' => $schema->string()->description('Where the lead was found (URL or source name).'),
            'export_contract_usd' => $schema->integer()->min(0),
            'parent_revenue_usd' => $schema->integer()->min(0),
            'sanctions_status' => $schema->string()->enum(CompanyService::SANCTIONS),
        ];
    }
}
