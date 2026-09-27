<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use App\Domains\Advisor\Outreach\Services\StatsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_stats')]
#[Description('Funnel metrics per target country: found, verified, sent, replied, meetings, positive replies, reply and meeting rates.')]
#[IsReadOnly]
class GetStats extends OutreachTool
{
    public function handle(Request $request, OutreachGate $gate, StatsService $stats): Response
    {
        $f = $request->validate(['wave' => ['nullable', 'string', 'max:16']]);

        return $this->read(function () use ($gate, $stats, $f): array {
            $gate->require($this->actor(), OutreachGate::VIEW);
            $summary = $stats->summary($this->actor(), $f['wave'] ?? null);
            unset($summary['by_owner']); // advisor workload is not the agent's business

            return $summary;
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'wave' => $schema->string()->description('Country wave filter: 1, 2 or investor.'),
        ];
    }
}
