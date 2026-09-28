<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Services\CountryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('upsert_country')]
#[Description('Record or update a target country after researching it (IT outsourcing market size, English/Russian/Turkish usage, time zone overlap with Uzbekistan, existing nearshore activity, legal/sanctions risk). Companies can only be added for countries recorded here. wave: 1 (best fit, start now), 2 (later), investor (capital source rather than delivery partner). score 0-100 = your fit estimate; put the reasoning in notes and the data sources in source. You may set excluded=true with a reason (e.g. comprehensive sanctions); you can never lift an exclusion — only a person can.')]
#[IsIdempotent]
class UpsertCountry extends OutreachTool
{
    public function handle(Request $request, CountryService $countries): Response
    {
        $data = $request->validate(CountryService::rules());

        return $this->write(function () use ($countries, $data): array {
            $result = $countries->upsert($this->actor(), $data);
            $c = $result['country'];

            return [
                'created' => $result['created'],
                'country' => [
                    'code' => $c->code, 'name' => $c->name, 'wave' => $c->wave, 'score' => $c->score,
                    'excluded' => $c->excluded, 'excluded_reason' => $c->excluded_reason,
                    'default_language' => $c->default_language, 'timezone' => $c->timezone,
                ],
            ];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->description('ISO 3166-1 alpha-2, e.g. DE, TR, US.')->required(),
            'name' => $schema->string()->description('Defaults to the ISO English name.'),
            'wave' => $schema->string()->enum(CountryService::WAVES),
            'score' => $schema->integer()->min(0)->max(100),
            'default_language' => $schema->string()->description('Main business language for letters, ISO 639-1 (en, ru, tr, de...).'),
            'timezone' => $schema->string()->description('IANA time zone of the main business region, e.g. Europe/Berlin (used for sending hours).'),
            'notes' => $schema->string()->description('Why this wave/score: short research summary.'),
            'source' => $schema->string()->description('Sources used (URLs or names).'),
            'excluded' => $schema->boolean()->description('Only true is accepted from you; exclusion needs excluded_reason.'),
            'excluded_reason' => $schema->string(),
        ];
    }
}
