<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Models\ObjectAuditLog;
use App\Domains\Qurilish\Services\ObjectService;
use App\Domains\Qurilish\Support\QurilishAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Obyekt o'zgarishlari jurnali — nazorat organi (prokuratura) uchun.
 * Foydalanuvchi nomi `auth.users` dan olinadi (cross-schema join emas,
 * alohida so'rov: ulanishlar ajratilgan).
 */
class AuditController extends QurilishController
{
    public function __construct(QurilishAccess $access, private readonly ObjectService $objects)
    {
        parent::__construct($access);
    }

    public function __invoke(Request $request, string $objectId): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        $object = $this->objects->findOrFail($request->user(), $objectId);

        $rows = ObjectAuditLog::query()
            ->where('object_id', $object->id)
            ->orderByDesc('created_at')
            ->limit(300)
            ->get();

        $names = DB::connection('auth')->table('users')
            ->whereIn('id', $rows->pluck('user_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return response()->json([
            'data' => $rows->map(fn (ObjectAuditLog $r) => [
                'id' => $r->id,
                'action' => $r->action,
                'field' => $r->field,
                'old_value' => $r->old_value,
                'new_value' => $r->new_value,
                'user' => $r->user_id === null ? null : ($names[$r->user_id] ?? null),
                'created_at' => $r->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
