<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Services\ActivityService;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Domains\Advisor\Support\Period;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FAOLIYAT NAZORATI — har maslahatchi bo'yicha agregat (spec §9). FAQAT
 * viloyat/bo'linma (oversight.view): barcha maslahatchilar kesimi. KPI o'rtacha
 * uchun davr berilmasa joriy chorak.
 */
class OversightController extends Controller
{
    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly ActivityService $activity,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'oversight.view'), 403, 'Фаолият назоратига рухсат йўқ.');

        $v = $request->validate([
            'period' => ['nullable', 'string', 'max:12'],
        ]);

        return response()->json($this->activity->oversight($v['period'] ?? Period::current()));
    }
}
