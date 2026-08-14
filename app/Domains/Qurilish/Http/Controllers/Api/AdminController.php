<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Services\ReferenceAdminService;
use App\Domains\Qurilish\Services\UserAdminService;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tizim moderatori ish o'rni: hisoblar va spravochniklar.
 *
 * Ikki ruxsat ajratilgan — `qurilish.user.manage` (hisob) va
 * `qurilish.reference.manage` (dastur/soha/tashkilot). Ular alohida:
 * kelajakda faqat spravochnik yurituvchi rol qo'shilsa, unga hisob ochish
 * huquqi «qo'shimcha» bo'lib kelib qolmasin.
 */
class AdminController extends QurilishController
{
    public function __construct(
        QurilishAccess $access,
        private readonly UserAdminService $users,
        private readonly ReferenceAdminService $reference,
    ) {
        parent::__construct($access);
    }

    // ---------- Hisoblar ----------

    public function userIndex(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.user.manage');

        $rows = $this->users->list(
            $request->query('search') === null ? null : (string) $request->query('search'),
            $request->query('role') === null ? null : (string) $request->query('role'),
        );

        return response()->json([
            'data' => $rows->values()->all(),
            'roles' => $this->roleOptions(),
        ]);
    }

    public function userStore(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.user.manage');

        $data = $request->validate([
            'login' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9_.-]+$/'],
            'name' => ['required', 'string', 'min:3', 'max:200'],
            'role' => ['required', 'string'],
            'organization_id' => ['nullable', 'uuid'],
            'position' => ['nullable', 'string', 'max:200'],
            // Parol ixtiyoriy: kiritilmasa server kuchli parol generatsiya qiladi.
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
        ], [
            'login.regex' => 'Логин фақат лотин кичик ҳарф, рақам, нуқта, тире ва пастки чизиқдан иборат бўлади.',
        ]);

        $result = $this->users->create($data, $request->user());

        return response()->json([
            'data' => $result['user'],
            // Parol FAQAT shu javobda qaytadi va boshqa hech qayerda saqlanmaydi.
            'password' => $result['password'],
            'message' => 'Ҳисоб яратилди. Паролни ҳозир кўчириб олинг — у қайта кўрсатилмайди.',
        ], 201);
    }

    public function userUpdate(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.user.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:3', 'max:200'],
            'role' => ['sometimes', 'string'],
            'organization_id' => ['sometimes', 'nullable', 'uuid'],
            'position' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        return response()->json(['data' => $this->users->update($id, $data, $request->user())]);
    }

    public function userResetPassword(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.user.manage');

        $data = $request->validate(['password' => ['nullable', 'string', 'min:8', 'max:100']]);
        $password = $this->users->resetPassword($id, $request->user(), $data['password'] ?? null);

        return response()->json([
            'password' => $password,
            'message' => 'Парол янгиланди. Уни ҳозир кўчириб олинг.',
        ]);
    }

    public function userSetActive(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.user.manage');

        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        return response()->json([
            'data' => $this->users->setActive($id, (bool) $data['is_active'], $request->user()),
        ]);
    }

    // ---------- Dasturlar ----------

    public function programIndex(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.reference.manage');

        return response()->json(['data' => $this->reference->programs()->values()->all()]);
    }

    public function programStore(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.reference.manage');
        $program = $this->reference->createProgram($this->programRules($request), $request->user());

        return response()->json(['data' => ['id' => $program->id, 'name' => $program->name_cyr]], 201);
    }

    public function programUpdate(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.reference.manage');
        $program = $this->reference->updateProgram($id, $this->programRules($request), $request->user());

        return response()->json(['data' => ['id' => $program->id, 'name' => $program->name_cyr]]);
    }

    public function programDestroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.reference.manage');

        return response()->json(['message' => $this->reference->deleteProgram($id, $request->user())]);
    }

    // ---------- Sohalar ----------

    public function sectorIndex(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.reference.manage');

        return response()->json(['data' => $this->reference->sectors()->values()->all()]);
    }

    public function sectorStore(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.reference.manage');
        $sector = $this->reference->createSector($this->sectorRules($request), $request->user());

        return response()->json(['data' => ['id' => $sector->id, 'name' => $sector->name_cyr]], 201);
    }

    public function sectorUpdate(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.reference.manage');
        $sector = $this->reference->updateSector($id, $this->sectorRules($request), $request->user());

        return response()->json(['data' => ['id' => $sector->id, 'name' => $sector->name_cyr]]);
    }

    public function sectorDestroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.reference.manage');

        return response()->json(['message' => $this->reference->deleteSector($id, $request->user())]);
    }

    // ---------- Tashkilotlar ----------

    public function organizationIndex(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.reference.manage');

        return response()->json([
            'data' => $this->reference->organizations(
                $request->query('search') === null ? null : (string) $request->query('search'),
                $request->query('flag') === null ? null : (string) $request->query('flag'),
            )->values()->all(),
        ]);
    }

    public function organizationSave(Request $request, ?string $id = null): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.reference.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:500'],
            'name_lat' => ['nullable', 'string', 'max:500'],
            'short_name' => ['nullable', 'string', 'max:200'],
            'inn' => ['nullable', 'string', 'max:20'],
            'is_customer' => ['nullable', 'boolean'],
            'is_designer' => ['nullable', 'boolean'],
            'is_contractor' => ['nullable', 'boolean'],
            'is_department' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $org = $this->reference->saveOrganization($id, $data, $request->user());

        return response()->json(['data' => ['id' => $org->id, 'name' => $org->name_cyr]], $id === null ? 201 : 200);
    }

    // ---------- Jurnal ----------

    public function auditIndex(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.user.manage');

        $rows = $this->reference->auditLog(200);

        // Ijrochi ismini bitta so'rov bilan qo'shamiz — jurnal «kim» siz o'qilmaydi.
        $names = User::query()
            ->whereIn('id', $rows->pluck('actor_id')->unique()->all())
            ->pluck('name', 'id');

        return response()->json([
            'data' => $rows->map(fn (array $r) => $r + ['actor_name' => $names[$r['actor_id']] ?? '—'])->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function programRules(Request $request): array
    {
        return $request->validate([
            'code' => ['sometimes', 'string', 'max:40'],
            'name' => ['sometimes', 'string', 'max:300'],
            'name_lat' => ['nullable', 'string', 'max:300'],
            'legal_basis' => ['nullable', 'string', 'max:60'],
            'year' => ['nullable', 'integer', 'min:2020', 'max:2050'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function sectorRules(Request $request): array
    {
        return $request->validate([
            'code' => ['sometimes', 'string', 'max:40'],
            'name' => ['sometimes', 'string', 'max:300'],
            'name_lat' => ['nullable', 'string', 'max:300'],
            'default_department_org_id' => ['nullable', 'uuid'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * Rol ro'yxati + har biri uchun tashkilot talabi.
     *
     * `org_flag` — SPA qaysi TURDAGI tashkilotni ko'rsatishini biladi:
     * boshqarmaga pudratchi MChJ taklif qilinmasin.
     *
     * @return array<int, array{code: string, name: string, needs_org: bool, org_flag: ?string}>
     */
    private function roleOptions(): array
    {
        $needsOrg = [
            'qurilish_buyurtmachi' => 'is_customer',
            'qurilish_boshqarma' => 'is_department',
        ];

        return array_map(fn (string $code) => [
            'code' => $code,
            'name' => QurilishAccess::ROLE_NAMES[$code] ?? $code,
            'needs_org' => isset($needsOrg[$code]),
            'org_flag' => $needsOrg[$code] ?? null,
        ], QurilishAccess::ROLES);
    }
}
