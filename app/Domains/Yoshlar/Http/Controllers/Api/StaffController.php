<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Models\Staff;
use App\Domains\Yoshlar\Services\AuditLogger;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.view'), 403, 'Ruxsat yo‘q.');

        return response()->json([
            'data' => Staff::query()->with('organization:id,name_lat,type')->orderBy('created_at')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'org_id' => ['required', 'uuid'],
            'position' => ['nullable', 'string', 'max:200'],
            'can_patronage' => ['boolean'],
        ]);

        // Partial unique indeks (bitta faol xodim — bitta tashkilot) buzilib
        // 500 qaytmasin: oldindan tekshirib tushunarli 422 beramiz.
        $exists = Staff::query()->where('user_id', $data['user_id'])->where('is_active', true)->exists();
        abort_if($exists, 422, 'Bu foydalanuvchi allaqachon boshqa tashkilotga biriktirilgan.');

        $staff = Staff::query()->create($data + ['is_active' => true]);
        $this->audit->log($request->user(), 'staff.create', 'staff', $staff->id, $data);

        return response()->json(['data' => $staff], 201);
    }

    public function update(Request $request, string $staff): JsonResponse
    {
        $this->authorizeManage($request);

        $model = Staff::query()->findOrFail($staff);
        $data = $request->validate([
            'position' => ['sometimes', 'nullable', 'string', 'max:200'],
            'can_patronage' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->update($data);
        $this->audit->log($request->user(), 'staff.update', 'staff', $model->id, $data);

        return response()->json(['data' => $model->refresh()]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.staff.manage'), 403, 'Ruxsat yo‘q.');
    }
}
