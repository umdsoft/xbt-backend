<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Admin;

use App\Domains\Mahalla\Http\Requests\Admin\StoreMahallaUserRequest;
use App\Domains\Mahalla\Http\Requests\Admin\UpdateMahallaUserRequest;
use App\Domains\Mahalla\Models\MahallaProfile;
use App\Domains\Mahalla\Models\Master\Mahalla;
use App\Domains\Mahalla\Models\StreetAssignment;
use App\Domains\Mahalla\Support\MahallaAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ADMIN — operatsion (deputat) userlar boshqaruvi (markaziy identifikatsiya + geo-scope).
 * Faqat mahalla tizimidagi `deputat` rolli userlarni ko'radi/tahrirlaydi.
 * Markaziy identifikatsiya (auth.users) HECH QACHON hard-delete qilinmaydi.
 */
class UserManagementController extends Controller
{
    /**
     * Operatsion userlar ro'yxati (biriktirilgan mahalla + ko'chalar bilan).
     */
    public function index(): JsonResponse
    {
        $rows = $this->operationalUserRows();

        $profiles = MahallaProfile::query()
            ->with(['mahalla:id,name_cyr', 'streetAssignments.street:id,name'])
            ->whereIn('id', $rows->pluck('id')->all())
            ->get()
            ->keyBy('id');

        $users = $rows
            ->map(fn (object $u) => $this->formatUser($u, $profiles->get($u->id)))
            ->all();

        return response()->json(['users' => $users]);
    }

    /**
     * Yangi operatsion user — auth.users + user_system_access + mahalla profil + ko'chalar.
     */
    public function store(StoreMahallaUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $streetIds = array_values(array_unique($data['street_ids'] ?? []));
        $mahallaId = $data['mahalla_id'] ?? null;
        $districtId = $mahallaId !== null ? Mahalla::query()->whereKey($mahallaId)->value('district_id') : null;
        $adminId = $request->user()->id;

        // Konnektsiyalar aro atomarlik: mahalla tranzaksiyasi auth tranzaksiyasi ICHIDA —
        // profil/ko'cha yozuvi uzilsa, istisno auth tranzaksiyasini ham rollback qiladi.
        $userId = DB::connection('auth')->transaction(function () use ($data, $mahallaId, $districtId, $streetIds, $adminId) {
            $user = User::query()->create([
                'login' => $data['login'],
                'password' => $data['password'], // 'hashed' cast — avtomatik hash
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'is_active' => true,
            ]);

            $systemId = DB::connection('auth')->table('systems')
                ->where('code', MahallaAccess::SYSTEM_CODE)->value('id');

            DB::connection('auth')->table('user_system_access')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'system_id' => $systemId,
                'role' => 'deputat',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('mahalla')->transaction(function () use ($user, $data, $mahallaId, $districtId, $streetIds, $adminId) {
                MahallaProfile::query()->create([
                    'id' => $user->id,
                    'name' => $data['name'],
                    'login' => $data['login'],
                    'password' => $user->password, // legacy NOT NULL ustun — auth hash nusxasi
                    'district_id' => $districtId,
                    'mahalla_id' => $mahallaId,
                    'position' => $data['position'] ?? null,
                    'is_active' => true,
                ]);

                $this->syncStreetAssignments($user->id, $streetIds, $adminId);
            });

            return $user->id;
        });

        return response()->json(['user' => $this->userItem($userId)], 201);
    }

    /**
     * Operatsion userni yangilash (ism/parol/holat + mahalla + ko'chalar almashtirish).
     */
    public function update(UpdateMahallaUserRequest $request, string $id): JsonResponse
    {
        $this->resolveOperationalTarget($id);

        $data = $request->validated();
        $adminId = $request->user()->id;
        $mahallaProvided = $request->exists('mahalla_id');
        $mahallaId = $data['mahalla_id'] ?? null;
        $districtId = $mahallaId !== null ? Mahalla::query()->whereKey($mahallaId)->value('district_id') : null;

        // 1) auth.users — ism/parol/holat.
        DB::connection('auth')->transaction(function () use ($id, $data) {
            $user = User::query()->findOrFail($id);
            if (array_key_exists('name', $data)) {
                $user->name = $data['name'];
            }
            if (array_key_exists('phone', $data)) {
                $user->phone = $data['phone'];
            }
            if (! empty($data['password'])) {
                $user->password = $data['password']; // 'hashed' cast
            }
            if (array_key_exists('is_active', $data)) {
                $user->is_active = (bool) $data['is_active'];
                // Tizim ruxsati holatini ham sinxronlash (login/kirish nazorati).
                DB::connection('auth')->table('user_system_access as usa')
                    ->join('systems as s', 's.id', '=', 'usa.system_id')
                    ->where('usa.user_id', $id)
                    ->where('s.code', MahallaAccess::SYSTEM_CODE)
                    ->update(['usa.is_active' => (bool) $data['is_active'], 'usa.updated_at' => now()]);
            }
            $user->save();
        });

        // 2) mahalla profil + ko'chalar.
        DB::connection('mahalla')->transaction(function () use ($id, $data, $mahallaProvided, $mahallaId, $districtId, $adminId) {
            $profile = MahallaProfile::query()->find($id);
            if ($profile !== null) {
                if (array_key_exists('name', $data)) {
                    $profile->name = $data['name'];
                }
                if ($mahallaProvided) {
                    $profile->mahalla_id = $mahallaId;
                    $profile->district_id = $districtId;
                }
                if (array_key_exists('position', $data)) {
                    $profile->position = $data['position'];
                }
                if (array_key_exists('is_active', $data)) {
                    $profile->is_active = (bool) $data['is_active'];
                }
                $profile->save();
            }

            if (array_key_exists('street_ids', $data)) {
                $streetIds = array_values(array_unique($data['street_ids'] ?? []));
                $this->syncStreetAssignments($id, $streetIds, $adminId);
            }
        });

        return response()->json(['user' => $this->userItem($id)]);
    }

    /**
     * Operatsion userni O'CHIRMASDAN faolsizlantirish (login bloklanadi).
     */
    public function destroy(string $id): JsonResponse
    {
        $this->resolveOperationalTarget($id);

        DB::connection('auth')->transaction(function () use ($id) {
            DB::connection('auth')->table('users')->where('id', $id)
                ->update(['is_active' => false, 'updated_at' => now()]);

            DB::connection('auth')->table('user_system_access as usa')
                ->join('systems as s', 's.id', '=', 'usa.system_id')
                ->where('usa.user_id', $id)
                ->where('s.code', MahallaAccess::SYSTEM_CODE)
                ->update(['usa.is_active' => false, 'usa.updated_at' => now()]);
        });

        DB::connection('mahalla')->table('users')->where('id', $id)
            ->update(['is_active' => false, 'updated_at' => now()]);

        return response()->json(['user' => $this->userItem($id)]);
    }

    /**
     * Operatsion (deputat-rol, mahalla tizimi) userlar — auth qatorlari.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function operationalUserRows(): \Illuminate\Support\Collection
    {
        return DB::connection('auth')->table('user_system_access as usa')
            ->join('systems as s', 's.id', '=', 'usa.system_id')
            ->join('users as u', 'u.id', '=', 'usa.user_id')
            ->where('s.code', MahallaAccess::SYSTEM_CODE)
            ->where('usa.role', 'deputat')
            ->whereNull('u.deleted_at')
            ->orderBy('u.login')
            ->get(['u.id', 'u.login', 'u.name', 'u.phone', 'u.is_active', 'u.last_login_at']);
    }

    /**
     * Target user operatsion (deputat) a'zomi? Aks holda 404.
     */
    private function resolveOperationalTarget(string $id): void
    {
        $exists = DB::connection('auth')->table('user_system_access as usa')
            ->join('systems as s', 's.id', '=', 'usa.system_id')
            ->where('usa.user_id', $id)
            ->where('s.code', MahallaAccess::SYSTEM_CODE)
            ->where('usa.role', 'deputat')
            ->exists();

        if (! $exists) {
            throw new NotFoundHttpException('Оператсион фойдаланувчи топилмади.');
        }
    }

    /**
     * Ko'cha biriktiruvlarini berilgan ro'yxatga almashtirish (idempotent replace).
     *
     * @param  array<int, string>  $streetIds
     */
    private function syncStreetAssignments(string $userId, array $streetIds, ?string $adminId): void
    {
        StreetAssignment::query()->where('user_id', $userId)->delete();

        foreach ($streetIds as $streetId) {
            StreetAssignment::query()->create([
                'street_id' => $streetId,
                'user_id' => $userId,
                'assigned_by' => $adminId,
            ]);
        }
    }

    /**
     * Bitta user (id bo'yicha) — ro'yxat elementi bilan bir xil shakl.
     *
     * @return array<string, mixed>|null
     */
    private function userItem(string $id): ?array
    {
        $u = DB::connection('auth')->table('users')->where('id', $id)
            ->first(['id', 'login', 'name', 'phone', 'is_active', 'last_login_at']);

        if ($u === null) {
            return null;
        }

        $profile = MahallaProfile::query()
            ->with(['mahalla:id,name_cyr', 'streetAssignments.street:id,name'])
            ->find($id);

        return $this->formatUser($u, $profile);
    }

    /**
     * Yagona user shakli (index + store + update + destroy uchun bir xil).
     *
     * @return array<string, mixed>
     */
    private function formatUser(object $authRow, ?MahallaProfile $profile): array
    {
        return [
            'id' => $authRow->id,
            'login' => $authRow->login,
            'name' => $authRow->name,
            'phone' => $authRow->phone,
            'is_active' => (bool) $authRow->is_active,
            'last_login_at' => $authRow->last_login_at
                ? Carbon::parse($authRow->last_login_at)->toIso8601String()
                : null,
            'mahalla' => $profile?->mahalla ? [
                'id' => $profile->mahalla->id,
                'name' => $profile->mahalla->name_cyr,
            ] : null,
            'position' => $profile?->position,
            'position_label' => MahallaAccess::positionLabel($profile?->position),
            'streets' => $profile
                ? $profile->streetAssignments
                    ->map(fn (StreetAssignment $sa) => [
                        'id' => $sa->street_id,
                        'name' => $sa->street?->name,
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }
}
