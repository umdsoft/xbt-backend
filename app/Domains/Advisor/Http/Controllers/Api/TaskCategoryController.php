<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Services\TaskService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Topshiriq kategoriyalari (arxiv/qidiruv picker).
 *
 * SHARTNOMA (frontend tayanadi): [ {"id","name"} ].
 */
class TaskCategoryController extends Controller
{
    public function __invoke(TaskService $tasks): JsonResponse
    {
        return response()->json($tasks->categories());
    }
}
