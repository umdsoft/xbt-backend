<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Services\ContactService;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('upsert_contacts')]
#[Description('Bulk form of upsert_contact for up to 50 decision makers in one call, with the same rules (max 2 active contacts per company, email unique across companies, never guess emails). Each item is processed on its own: results[] says per item ok/created/contact_id, or the reason it was refused (contact_limit, duplicate, not_found, invalid_input, ...). Costs one write per item.')]
#[IsIdempotent]
class UpsertContacts extends OutreachTool
{
    public const MAX_ITEMS = 50;

    public function handle(Request $request, ContactService $contacts): Response
    {
        $items = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*' => ['array'],
        ])['items'];

        return $this->write(function () use ($contacts, $items): array {
            $results = [];
            foreach (array_values($items) as $i => $item) {
                $results[] = ['index' => $i, 'email' => $item['email'] ?? null] + $this->one($contacts, $item);
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
    private function one(ContactService $contacts, array $item): array
    {
        $validator = Validator::make($item, ContactService::rules());
        if ($validator->fails()) {
            return ['ok' => false, 'reason' => RuleViolation::INVALID_INPUT, 'errors' => $validator->errors()->toArray()];
        }

        try {
            $result = $contacts->upsert($this->actor(), $validator->validated());
        } catch (RuleViolation $e) {
            return ['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage(), 'context' => (object) $e->context];
        }

        return [
            'ok' => true,
            'created' => $result['created'],
            'contact_id' => $result['contact']->id,
            'company_id' => $result['contact']->company_id,
            'active' => $result['contact']->isActive(),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->min(1)->max(self::MAX_ITEMS)
                ->items($schema->object(UpsertContact::properties($schema)))
                ->description('Contacts with the same fields as upsert_contact.')
                ->required(),
        ];
    }
}
