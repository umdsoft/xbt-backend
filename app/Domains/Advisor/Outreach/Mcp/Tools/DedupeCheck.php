<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Services\DedupeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('dedupe_check')]
#[Description('Check whether a domain and/or email is already in the CRM before researching it further. Returns normalized values and matches.')]
#[IsReadOnly]
class DedupeCheck extends OutreachTool
{
    public function handle(Request $request, DedupeService $dedupe): Response
    {
        $data = $request->validate([
            'domain' => ['nullable', 'string', 'max:255', 'required_without:email'],
            'email' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->read(fn (): array => $dedupe->check($this->actor(), $data['domain'] ?? null, $data['email'] ?? null));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'domain' => $schema->string(),
            'email' => $schema->string(),
        ];
    }
}
