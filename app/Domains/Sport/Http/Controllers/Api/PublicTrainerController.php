<?php

declare(strict_types=1);

namespace App\Domains\Sport\Http\Controllers\Api;

use App\Domains\Sport\Services\TrainerCoverage;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Ochiq (loginsiz) sport trenerlar qamrovi API'si. Faqat O'QISH + agregatlar;
 * PII (telefon/passport) QAYTARILMAYDI.
 */
class PublicTrainerController extends Controller
{
    public function __construct(private readonly TrainerCoverage $coverage) {}

    /** Viloyat darajasidagi umumiy qamrov. */
    public function overview(): JsonResponse
    {
        return response()->json($this->coverage->overview());
    }

    /** Bitta tuman kesimi (trenerlar + qamrov bo'shlig'i). */
    public function district(string $district): JsonResponse
    {
        $data = $this->coverage->district($district);
        if ($data === []) {
            return response()->json(['message' => 'Туман топилмади'], 404);
        }

        return response()->json($data);
    }
}
