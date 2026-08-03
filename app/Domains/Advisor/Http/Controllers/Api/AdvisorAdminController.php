<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Services\AdvisorAdminService;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MASLAHATCHILAR (hisoblar) boshqaruvi — FAQAT viloyat super-admin ('advisors.manage',
 * ya'ni `*` — bo'linma/tuman KIRA OLMAYDI). Ro'yxat / yaratish / parol reset /
 * faol-nofaol / tahrir. Parollar bir marta (yaratish/reset javobiда) qaytariladi.
 */
class AdvisorAdminController extends Controller
{
    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly AdvisorAdminService $admin,
    ) {}

    /** Barcha maslahatchilar ro'yxati. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json($this->admin->list());
    }

    /** Yangi maslahatchi — parol generatsiya qilinadi (bir marta qaytariladi). */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate([
            'login' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9_.]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'level' => ['required', 'string', 'in:viloyat,bolinma,tuman'],
            'district_id' => ['nullable', 'required_if:level,tuman', 'uuid'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $creds = $this->admin->create($v);

        return response()->json(['ok' => true, 'credentials' => $creds], 201);
    }

    /** Parolni reset qiladi — yangi parol bir marta qaytariladi. */
    public function resetPassword(Request $request, string $user): JsonResponse
    {
        $this->authorizeManage($request);

        $creds = $this->admin->resetPassword($user);

        return response()->json(['ok' => true, 'credentials' => $creds]);
    }

    /** Profil/holatни tahrirlaydi (ism / hudud / telefon / faol-nofaol). */
    public function update(Request $request, string $user): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'district_id' => ['sometimes', 'nullable', 'uuid'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('active', $v)) {
            $this->admin->setActive($user, (bool) $v['active']);
            unset($v['active']);
        }

        if ($v !== []) {
            $this->admin->update($user, $v);
        }

        return response()->json(['ok' => true]);
    }

    /** FAQAT viloyat super-admin (advisors.manage = `*`). */
    private function authorizeManage(Request $request): void
    {
        abort_unless(
            $this->access->can($request->user(), 'advisors.manage'),
            403,
            'Маслаҳатчилар ҳисобларини фақат вилоят маслаҳатчиси бошқаради.',
        );
    }
}
