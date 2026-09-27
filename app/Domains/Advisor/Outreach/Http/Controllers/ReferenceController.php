<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Http\Controllers;

use App\Domains\Advisor\Models\Advisor;
use App\Domains\Advisor\Outreach\Http\OutreachPresenter;
use App\Domains\Advisor\Outreach\Models\Country;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use App\Domains\Advisor\Outreach\Services\StatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Countries, advisors (owner picker) and funnel statistics. */
class ReferenceController extends OutreachController
{
    public function __construct(
        OutreachGate $gate,
        OutreachPresenter $present,
        private readonly StatsService $stats,
    ) {
        parent::__construct($gate, $present);
    }

    public function countries(Request $request): JsonResponse
    {
        $this->authorizeTo($request, OutreachGate::VIEW);

        return response()->json([
            'data' => Country::query()->orderBy('excluded')->orderByDesc('score')->orderBy('name')
                ->get(['code', 'name', 'wave', 'score', 'excluded', 'excluded_reason']),
        ]);
    }

    public function advisors(Request $request): JsonResponse
    {
        $this->authorizeTo($request, OutreachGate::ALL_LEADS);

        // Only levels that may own leads (bolinma has no outreach rights).
        $advisors = Advisor::query()->with('district')
            ->where('active', true)->whereIn('level', ['viloyat', 'tuman'])
            ->orderBy('level')->get();
        $this->present->preloadNames($advisors->pluck('user_id'));

        return response()->json([
            'data' => $advisors
                ->filter(fn (Advisor $a) => $a->user_id !== null)
                ->map(fn (Advisor $a) => [
                    'user_id' => $a->user_id,
                    'name' => $this->present->name($a->user_id),
                    'level' => $a->level,
                    'district_name' => $a->district?->name,
                ])->values()->all(),
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $actor = $this->authorizeTo($request, OutreachGate::VIEW);
        $wave = $request->validate(['wave' => ['nullable', 'string', 'max:16']])['wave'] ?? null;

        $summary = $this->stats->summary($actor, $wave);
        if (isset($summary['by_owner'])) {
            $this->present->preloadNames(array_column($summary['by_owner'], 'owner_user_id'));
            $summary['by_owner'] = array_map(
                fn (array $row): array => $row + ['owner_name' => $this->present->name($row['owner_user_id'])],
                $summary['by_owner'],
            );
        }

        return response()->json($summary);
    }
}
