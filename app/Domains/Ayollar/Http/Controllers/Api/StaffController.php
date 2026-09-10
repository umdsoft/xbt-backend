<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Models\Staff;
use App\Domains\Ayollar\Services\AuditLogger;
use App\Domains\Ayollar\Services\StaffProvisioner;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * FOYDALANUVCHILAR — administrator uchun.
 *
 * Shu paytgacha hisob faqat serverdagi CLI buyrug'i bilan ochilardi
 * (`ayollar:make-user`). Bu ishlaydi, lekin tuman administratori
 * serverga kira olmaydi va har yangi faol uchun dasturchiga murojaat
 * qilishi kerak edi. 509 MFY uchun bu ish tartibi emas.
 *
 * PAROL FAQAT BIR MARTA KO'RSATILADI. Yaratilgandan keyin u
 * hash'lanadi va qaytarib bo'lmaydi — administrator uni o'sha zahoti
 * yozib olishi kerak. Aks holda parolni «eslab qolish» uchun uni
 * biror joyda ochiq saqlashga to'g'ri kelardi.
 */
class StaffController extends Controller
{
    /** Administrator ocha oladigan rollar. */
    private const ASSIGNABLE_ROLES = [
        AyollarAccess::ROLE_ACTIVIST,
        AyollarAccess::ROLE_CHAIRMAN,
        AyollarAccess::ROLE_HOKIM_ASSISTANT,
        AyollarAccess::ROLE_FAMILY_DEPT,
        AyollarAccess::ROLE_DISTRICT_ORG,
        AyollarAccess::ROLE_ANALYST,
    ];

    public function __construct(
        private readonly AyollarAccess $access,
        private readonly StaffProvisioner $provisioner,
        private readonly AuditLogger $audit,
    ) {}

    /** Hisoblar ro'yxati — tuman/MFY nomlari bilan. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize($request);

        $query = Staff::query();

        if ($request->filled('district_id')) {
            $query->where('district_id', $request->string('district_id')->toString());
        }

        if ($request->filled('mahalla_id')) {
            $query->where('mahalla_id', $request->string('mahalla_id')->toString());
        }

        $staff = $query->orderBy('created_at', 'desc')->limit(500)->get();
        $userIds = $staff->pluck('user_id')->filter()->all();

        $users = DB::connection('auth')->table('users')
            ->whereIn('id', $userIds)
            ->get(['id', 'login', 'name', 'phone', 'is_active', 'last_login_at'])
            ->keyBy('id');

        $systemId = DB::connection('auth')->table('systems')
            ->where('code', AyollarAccess::SYSTEM_CODE)->value('id');

        $roles = DB::connection('auth')->table('user_system_access')
            ->whereIn('user_id', $userIds)->where('system_id', $systemId)
            ->pluck('role', 'user_id');

        $districts = DB::connection('master')->table('districts')->pluck('name_lat', 'id');
        $mahallas = DB::connection('master')->table('mahallas')->pluck('name_lat', 'id');

        return response()->json([
            'staff' => $staff->map(function (Staff $s) use ($users, $roles, $districts, $mahallas) {
                $u = $users[$s->user_id] ?? null;
                $role = $roles[$s->user_id] ?? null;

                return [
                    'id' => $s->user_id,
                    'login' => $u->login ?? '—',
                    'name' => $u->name ?? '—',
                    'phone' => $u->phone ?? null,
                    'role' => $role,
                    'role_name' => $role === null ? '—' : (AyollarAccess::ROLE_NAMES[$role] ?? $role),
                    'position' => $s->position,
                    'district_id' => $s->district_id,
                    'district_name' => $districts[$s->district_id] ?? null,
                    'mahalla_id' => $s->mahalla_id,
                    'mahalla_name' => $mahallas[$s->mahalla_id] ?? null,
                    'is_active' => (bool) ($u->is_active ?? false),
                    'last_login_at' => $u->last_login_at ?? null,
                    'last_seen_at' => $s->last_seen_at,
                    'device_id' => $s->last_device_id,
                ];
            })->values(),
            /*
             * `requires_mahalla` SERVERDAN keladi — ekran uni o'zi
             * hisoblamaydi. Aks holda qoida ikki joyda yashardi va
             * biri o'zgarganda ikkinchisi eskirib qolardi: forma MFY
             * so'ramasdi, server esa rad etardi (yoki teskarisi).
             *
             * MFY faqat FAOL uchun majburiy: u aniq mahallada,
             * aniq ko'chalarda yuradi. Qolganlariga tuman yetarli —
             * tuman hokimi o'rinbosari butun tumanni ko'rishi kerak.
             */
            'roles' => array_map(fn ($r) => [
                'code' => $r,
                'name' => AyollarAccess::ROLE_NAMES[$r],
                'scope' => AyollarAccess::ROLE_SCOPE[$r],
                'requires_mahalla' => $r === AyollarAccess::ROLE_ACTIVIST,
                'requires_district' => AyollarAccess::ROLE_SCOPE[$r] !== AyollarAccess::SCOPE_REGION,
            ], self::ASSIGNABLE_ROLES),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize($request);

        $data = $this->validated($request, null);
        $data['password'] = $data['password'] ?? $this->provisioner->randomPassword();

        try {
            $result = $this->provisioner->save($data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->log($request->user(), 'staff.create', 'user', $result['user_id'], [
            'login' => $data['login'], 'role' => $data['role'],
        ]);

        return response()->json([
            'id' => $result['user_id'],
            // Parolni MANA SHU javobda qaytaramiz va boshqa hech qachon.
            'password' => $result['password'],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorize($request);

        $exists = DB::connection('auth')->table('users')->where('id', $id)->exists();

        if (! $exists) {
            abort(404, 'Hisob topilmadi.');
        }

        $data = $this->validated($request, $id);

        try {
            $this->provisioner->save($data, $id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->log($request->user(), 'staff.update', 'user', $id, [
            'login' => $data['login'], 'role' => $data['role'],
        ]);

        return response()->json([
            'id' => $id,
            'password' => $data['password'] ?? null,
        ]);
    }

    /** Parolni yangilaydi va yangisini BIR MARTA qaytaradi. */
    public function resetPassword(Request $request, string $id): JsonResponse
    {
        $this->authorize($request);

        $user = DB::connection('auth')->table('users')->where('id', $id)->first(['id', 'login']);

        if ($user === null) {
            abort(404, 'Hisob topilmadi.');
        }

        $password = $request->filled('password')
            ? $request->string('password')->toString()
            : $this->provisioner->randomPassword();

        DB::connection('auth')->table('users')->where('id', $id)->update([
            'password' => bcrypt($password),
            'updated_at' => now(),
        ]);

        $this->audit->log($request->user(), 'staff.password_reset', 'user', $id, [
            'login' => $user->login,
        ]);

        return response()->json(['password' => $password]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorize($request);

        if ((string) $request->user()->id === $id) {
            abort(422, 'O‘z hisobingizni o‘chira olmaysiz.');
        }

        $this->provisioner->deactivate($id);
        $this->audit->log($request->user(), 'staff.deactivate', 'user', $id, []);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?string $id): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
            'district_id' => ['nullable', 'uuid'],
            'mahalla_id' => ['nullable', 'uuid'],
            'position' => ['nullable', 'string', 'max:120'],
            'is_active' => ['boolean'],
            // Yaratishda ixtiyoriy (berilmasa tasodifiy hosil qilinadi),
            // tahrirlashda ham ixtiyoriy (berilmasa tegilmaydi).
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
            'login' => [
                'required', 'string', 'max:60', 'regex:/^[a-z0-9_.-]+$/',
                Rule::unique('auth.users', 'login')->ignore($id),
            ],
        ];

        $data = $request->validate($rules, [
            'login.regex' => 'Login faqat kichik lotin harflari, raqam va _ . - dan iborat bo‘lsin.',
        ]);

        // MFY tanlansa, tuman UNDAN olinadi. Administrator ikkalasini
        // alohida tanlaganda ular mos kelmasligi mumkin edi va
        // foydalanuvchi «tumani boshqa, MFY'si boshqa» holatga tushardi.
        if (! empty($data['mahalla_id'])) {
            $districtId = DB::connection('master')->table('mahallas')
                ->where('id', $data['mahalla_id'])->value('district_id');

            if ($districtId === null) {
                abort(422, 'MFY topilmadi.');
            }

            $data['district_id'] = (string) $districtId;
        }

        return $data;
    }

    private function authorize(Request $request): void
    {
        if (! $this->access->can($request->user(), 'ayollar.user.manage')) {
            abort(403, 'Ruxsat yo‘q.');
        }
    }
}
