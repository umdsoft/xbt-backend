<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Services\AuditLogger;
use App\Domains\Yoshlar\Services\UserAdminService;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Hisob boshqaruvi — faqat `yoshlar_admin`. Parol javobda BIR MARTA qaytadi
 * (saqlanmaydi); UI uni ko'rsatib, admin yozib olishi kerak.
 */
class AdminController extends Controller
{
    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly UserAdminService $service,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $rows = DB::connection('auth')->table('users as u')
            ->join('user_system_access as usa', 'usa.user_id', '=', 'u.id')
            ->join('systems as s', 's.id', '=', 'usa.system_id')
            ->where('s.code', YoshlarAccess::SYSTEM_CODE)
            ->whereNull('u.deleted_at')
            ->orderBy('u.name')
            ->get(['u.id', 'u.login', 'u.name', 'u.is_active', 'usa.role', 'u.last_login_at']);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'login' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:200'],
            'role' => ['required', 'string'],
            'org_id' => ['nullable', 'uuid'],
            'position' => ['nullable', 'string', 'max:200'],
            'can_patronage' => ['boolean'],
        ]);

        $result = $this->service->create(
            $data['login'], $data['name'], $data['role'],
            $data['org_id'] ?? null, $data['position'] ?? null,
            (bool) ($data['can_patronage'] ?? false),
        );

        $this->audit->log($request->user(), 'user.create', 'user', $result['user_id'], ['role' => $data['role']]);

        return response()->json($result, 201);
    }

    public function update(Request $request, string $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $target = User::on('auth')->findOrFail($user);

        $this->service->setActive($target, $data['is_active']);
        $this->audit->log($request->user(), 'user.update', 'user', $user, $data);

        return response()->json(['status' => 'ok']);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.user.manage'), 403, 'Ruxsat yo‘q.');
    }
}
