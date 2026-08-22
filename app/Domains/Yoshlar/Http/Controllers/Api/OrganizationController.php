<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Services\AuditLogger;
use App\Domains\Yoshlar\Services\OrganizationService;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly OrganizationService $service,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.view'), 403, 'Ruxsat yo‘q.');

        return response()->json([
            'data' => Organization::query()->orderBy('type')->orderBy('name_lat')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $org = $this->service->create($request->all());
        $this->audit->log($request->user(), 'organization.create', 'organization', $org->id, $org->only(['type', 'name_lat']));

        return response()->json(['data' => $org], 201);
    }

    public function update(Request $request, string $organization): JsonResponse
    {
        $this->authorizeManage($request);

        $org = Organization::query()->findOrFail($organization);
        $updated = $this->service->update($org, $request->all());
        $this->audit->log($request->user(), 'organization.update', 'organization', $org->id, $request->all());

        return response()->json(['data' => $updated]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.org.manage'), 403, 'Ruxsat yo‘q.');
    }
}
