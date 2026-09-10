<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Http\Requests\StoreOrganizationRequest;
use App\Domains\Hr\Http\Requests\UpdateOrganizationRequest;
use App\Domains\Hr\Models\Department;
use App\Domains\Hr\Models\Organization;
use App\Domains\Hr\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OrganizationController extends HrController
{
    public function __construct(private TenantContext $context)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Organization::class);

        $user = $this->actor();

        $query = Organization::query()
            ->with(['kompleks:id,name_cyr', 'creator:id,name'])
            ->withCount('users')
            ->when($request->filled('search'), fn ($q) => $q->whereLike('name_cyr', '%'.$request->search.'%'))
            ->latest();

        // Kotibyat mudiri — faqat o'z kompleksi/o'zi yaratgan tashkilotlar
        if ($user->hasRole('kotibyat-mudiri') && ! $user->hasRole('tuman-admin')) {
            $query->where(function ($q) use ($user) {
                $q->where('kompleks_id', $user->department_id)
                    ->orWhere('created_by', $user->id);
            });
        }

        return response()->json([
            'organizations' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only('search'),
        ]);
    }

    public function create(): JsonResponse
    {
        $this->authorize('create', Organization::class);

        return response()->json([
            'komplekslar' => $this->komplekslar(),
        ]);
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $this->authorize('create', Organization::class);

        $data = $request->validated();
        $data['created_by'] = $this->actor()->id;
        $data['kompleks_id'] = $this->resolveKompleksId($request);
        // hokimlik_id — BelongsToTenant creating hook avtomatik to'ldiradi;
        // cross-tenant admin uchun joriy tenantni aniq beramiz.
        if ($this->context->id() !== null) {
            $data['hokimlik_id'] = $this->context->id();
        }

        $org = Organization::create($data);

        return response()->json([
            'message' => 'Ташкилот яратилди.',
            'organization' => $org,
        ], 201);
    }

    public function show(Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        $organization->load([
            'kompleks:id,name_cyr',
            'creator:id,name',
            'users:id,name,login,organization_id',
            'users.roles:id,name',
        ]);

        return response()->json([
            'organization' => $organization,
            'canManageUsers' => $this->actor()->can('manageUsers', $organization),
        ]);
    }

    public function edit(Organization $organization): JsonResponse
    {
        $this->authorize('update', $organization);

        return response()->json([
            'organization' => $organization,
            'komplekslar' => $this->komplekslar(),
        ]);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('update', $organization);

        $data = $request->validated();
        // kompleks_id — faqat tenant ichidagi kompleksga ruxsat
        if (array_key_exists('kompleks_id', $data) && $data['kompleks_id'] !== null) {
            $data['kompleks_id'] = $this->validKompleksId((string) $data['kompleks_id']);
        }

        $organization->update($data);

        return response()->json([
            'message' => 'Ташкилот маълумотлари янгиланди.',
            'organization' => $organization->fresh(),
        ]);
    }

    public function destroy(Organization $organization): JsonResponse
    {
        $this->authorize('delete', $organization);

        $organization->delete();

        return response()->json([
            'message' => 'Ташкилот ўчирилди.',
        ]);
    }

    /**
     * Joriy tenant ichidagi komplekslar ro'yxati (form uchun).
     *
     * @return Collection<int, Department>
     */
    private function komplekslar()
    {
        return Department::query()
            ->komplekslar()
            ->when($this->context->id() !== null, fn ($q) => $q->where('parent_id', $this->context->id()))
            ->orderBy('sort_order')
            ->get(['id', 'name_cyr', 'parent_id']);
    }

    /** Store uchun kompleks_id ni aniqlash: kotibyat mudirining bo'limi yoki so'rovdan. */
    private function resolveKompleksId(Request $request): ?string
    {
        $user = $this->actor();

        // Kotibyat mudirining department_id'si kompleks bo'lsa — o'shani ishlatamiz
        if ($user->department && $user->department->type === 'kompleks') {
            return $user->department_id;
        }

        $requested = $request->input('kompleks_id');

        return $requested !== null ? $this->validKompleksId((string) $requested) : null;
    }

    /** kompleks_id joriy tenant ichidagi haqiqiy kompleks ekanini tekshiradi. */
    private function validKompleksId(string $kompleksId): ?string
    {
        $valid = Department::query()
            ->komplekslar()
            ->when($this->context->id() !== null, fn ($q) => $q->where('parent_id', $this->context->id()))
            ->whereKey($kompleksId)
            ->exists();

        return $valid ? $kompleksId : null;
    }
}
