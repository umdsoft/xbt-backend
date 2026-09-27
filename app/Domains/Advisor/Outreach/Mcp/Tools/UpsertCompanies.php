<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Services\CompanyService;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('upsert_companies')]
#[Description('Bulk form of upsert_company for up to 50 companies in one call, with the same rules. Each item is processed on its own: results[] says per item ok/created/company_id/tier, or the reason it was refused (country_excluded, duplicate, invalid_input, ...). Costs one write per item against the write limits.')]
#[IsIdempotent]
class UpsertCompanies extends OutreachTool
{
    public const MAX_ITEMS = 50;

    public function handle(Request $request, CompanyService $companies): Response
    {
        $items = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*' => ['array'],
        ])['items'];

        return $this->write(function () use ($companies, $items): array {
            $results = [];
            foreach (array_values($items) as $i => $item) {
                $results[] = ['index' => $i, 'domain' => $item['domain'] ?? null] + $this->one($companies, $item);
            }

            return [
                'ok' => count(array_filter($results, fn (array $r): bool => $r['ok'])),
                'failed' => count(array_filter($results, fn (array $r): bool => ! $r['ok'])),
                'results' => $results,
            ];
        }, count($items));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function one(CompanyService $companies, array $item): array
    {
        $validator = Validator::make($item, CompanyService::rules());
        if ($validator->fails()) {
            return ['ok' => false, 'reason' => RuleViolation::INVALID_INPUT, 'errors' => $validator->errors()->toArray()];
        }

        try {
            $result = $companies->upsert($this->actor(), $validator->validated());
        } catch (RuleViolation $e) {
            return ['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage(), 'context' => (object) $e->context];
        }

        $company = $result['company'];

        return [
            'ok' => true,
            'created' => $result['created'],
            'company_id' => $company->id,
            'stage' => $company->stage,
            'tier' => $company->tier,
            'icp_score' => $company->icp_score,
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->min(1)->max(self::MAX_ITEMS)
                ->items($schema->object(UpsertCompany::properties($schema)))
                ->description('Companies with the same fields as upsert_company.')
                ->required(),
        ];
    }
}
