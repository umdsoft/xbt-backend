<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Services\AyollarSummary;
use App\Domains\Mahalla\Support\ExecutiveScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rahbariyat: mahalla ichidagi АЁЛЛАР ХАТЛОВИ жамланмаси (faqat agregat).
 *
 * Ayollar domenining o'z RBAC'iga (`AyollarAccess`) tegilmaydi — qamrov
 * mavjud `mahalla.viewer` gvardiyasi + `ExecutiveScope::mahalla()` orqali
 * (Task 4 dagi uchta kontroller bilan bir xil namuna).
 */
class AyollarSummaryController extends Controller
{
    public function __construct(
        private readonly AyollarSummary $summary,
        private readonly ExecutiveScope $scope,
    ) {}

    public function __invoke(Request $request, string $mahalla): JsonResponse
    {
        $model = $this->scope->mahalla($request->user(), $mahalla);

        return response()->json([
            'mahalla' => [
                'id' => $model->id,
                'name' => $model->name_cyr,
            ],
            ...$this->summary->forMahalla((string) $model->id),
        ]);
    }
}
