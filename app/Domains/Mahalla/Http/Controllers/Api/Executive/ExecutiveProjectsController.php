<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Models\Master\Mahalla;
use App\Domains\Mahalla\Services\MicroProjectService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rahbariyat: mahalla ichidagi mikrolойiҳalar ro'yxati (faqat kо'rish).
 *
 * "Ободонлаштириш лойиҳалари" — hokim yordamchisi yuritadi; viewer (viloyat)
 * shu mahalla kesimida ko'radi. ProjectsList tabi ochilganda lazy yuklanadi.
 */
class ExecutiveProjectsController extends Controller
{
    public function __construct(private readonly MicroProjectService $projects)
    {
    }

    public function __invoke(Request $request, string $mahalla): JsonResponse
    {
        $model = Mahalla::on('master')->findOrFail($mahalla);

        $v = $request->validate([
            'status' => ['nullable', 'string', 'in:planned,in_progress,done,cancelled'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $list = $this->projects->list(
            (string) $model->id,
            $v['status'] ?? null,
            null,
            $v['q'] ?? null,
            (int) ($v['page'] ?? 1),
        );

        return response()->json([
            ...$list,
            'counts' => $this->projects->statusCounts((string) $model->id),
        ]);
    }
}
