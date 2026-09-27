<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp\Tools;

use App\Domains\Advisor\Outreach\Mcp\OutreachTool;
use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_pipeline')]
#[Description('List leads with optional filters, plus lead counts per stage. Use it to decide what to research, verify or follow up next.')]
#[IsReadOnly]
class GetPipeline extends OutreachTool
{
    private const MAX_LIMIT = 100;

    public function handle(Request $request, OutreachGate $gate): Response
    {
        $f = $request->validate([
            'stage' => ['nullable', 'in:'.implode(',', Stage::all())],
            'country_code' => ['nullable', 'string', 'size:2'],
            'tier' => ['nullable', 'in:A,B,C'],
            'updated_since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->read(function () use ($gate, $f): array {
            $gate->require($this->actor(), OutreachGate::VIEW);
            $base = $gate->scope(Company::query(), $this->actor());

            $filtered = (clone $base)
                ->when($f['stage'] ?? null, fn (Builder $q, string $v) => $q->where('stage', $v))
                ->when($f['country_code'] ?? null, fn (Builder $q, string $v) => $q->where('country_code', strtoupper($v)))
                ->when($f['tier'] ?? null, fn (Builder $q, string $v) => $q->where('tier', $v))
                ->when($f['updated_since'] ?? null, fn (Builder $q, string $v) => $q->where('updated_at', '>=', $v));

            $total = (clone $filtered)->count();
            $rows = $filtered->with('country')->withCount('contacts')
                ->orderByDesc('icp_score')->orderBy('created_at')
                ->offset($f['offset'] ?? 0)->limit($f['limit'] ?? 50)->get();

            return [
                'total' => $total,
                'by_stage' => (clone $base)->toBase()->selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage'),
                'companies' => $this->present()->companyRows($rows),
            ];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'stage' => $schema->string()->enum(Stage::all()),
            'country_code' => $schema->string(),
            'tier' => $schema->string()->enum(['A', 'B', 'C']),
            'updated_since' => $schema->string()->format('date-time'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)->default(50),
            'offset' => $schema->integer()->min(0),
        ];
    }
}
