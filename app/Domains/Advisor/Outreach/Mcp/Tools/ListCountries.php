<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Models\Country;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_countries')]
#[Description('Recorded target countries with wave, score, exclusion and how many leads each already has (total and tier A/B). Use it to decide where to hunt next and to avoid excluded countries.')]
#[IsReadOnly]
class ListCountries extends OutreachTool
{
    public function handle(Request $request, OutreachGate $gate): Response
    {
        return $this->read(function () use ($gate): array {
            $gate->require($this->actor(), OutreachGate::VIEW);

            $counts = DB::connection('advisor')->table('outreach_companies')
                ->selectRaw("country_code, count(*) as leads, count(*) filter (where tier in ('A','B')) as ab")
                ->groupBy('country_code')->get()->keyBy('country_code');

            return ['countries' => Country::query()->orderBy('excluded')->orderByDesc('score')->orderBy('code')->get()
                ->map(fn (Country $c): array => [
                    'code' => $c->code, 'name' => $c->name, 'wave' => $c->wave, 'score' => $c->score,
                    'excluded' => $c->excluded, 'excluded_reason' => $c->excluded_reason,
                    'default_language' => $c->default_language, 'timezone' => $c->timezone,
                    'leads' => (int) ($counts[$c->code]->leads ?? 0), 'leads_tier_ab' => (int) ($counts[$c->code]->ab ?? 0),
                ])->all()];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
