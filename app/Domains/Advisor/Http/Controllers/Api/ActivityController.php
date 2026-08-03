<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Services\ActivityService;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FAOLIYAT LENTASI (spec §9) — vaqt bo'yicha derived UNION. Viloyat/bo'linma
 * HAMMANI ko'radi (advisor_id/district_id filtri ixtiyoriy); tuman FAQAT o'z
 * tumani (qamrov service ichida majburlanadi — IDOR himoyasi).
 */
class ActivityController extends Controller
{
    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly ActivityService $activity,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'activity.view'), 403, 'Фаолият лентасини кўришга рухсат йўқ.');

        $v = $request->validate([
            'advisor_id' => ['nullable', 'uuid'],
            'district_id' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $scope = $this->access->scopeFor($user);

        return response()->json($this->activity->feed($scope, $v));
    }
}
