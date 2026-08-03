<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Services\RankingService;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REYTING — choraklik/oylik tuman reytingi (spec §8). Ko'rish: hamma advisor
 * (rankings.view); hisoblash: FAQAT viloyat (rankings.compute).
 */
class RankingController extends Controller
{
    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly RankingService $rankings,
    ) {}

    /** Berilgan period reyting jadvali — [{district,score,rank}]. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'rankings.view'), 403, 'Рейтингни кўришга рухсат йўқ.');

        $v = $request->validate([
            'period' => ['required', 'string', 'max:12'],
        ]);

        return response()->json($this->rankings->rankings($v['period']));
    }

    /** Reytingni hisoblash (FAQAT viloyat) — score + rank yoziladi. */
    public function compute(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'rankings.compute'), 403, 'Рейтингни ҳисоблашга рухсат йўқ.');

        $v = $request->validate([
            'period' => ['required', 'string', 'max:12'],
        ]);

        return response()->json(['ok' => true, 'rankings' => $this->rankings->compute($v['period'])]);
    }
}
