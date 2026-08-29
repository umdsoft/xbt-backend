<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Services\AuditLogger;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QURILMANI RO'YXATGA OLISH — planshet kirgandan keyin.
 *
 * Promt §11 `POST /api/auth/login` da «PIN + qurilma ID» talab qiladi.
 * Markaziy identifikatsiya qurilma haqida bilmaydi (u 8 ta tizimga
 * umumiy), shuning uchun qurilma ALOHIDA, kirgandan keyin qayd
 * etiladi — bu markaziy auth'ni modul talabiga moslashtirmaslikning
 * yagona to'g'ri yo'li.
 *
 * NIMA BERADI: faollar monitoringida «bir hafta kirmagan faol»
 * ko'rinadi. Anketa sonidan buni bilib bo'lmaydi — u shunchaki 0
 * bo'lib qoladi va sabab (planshet buzuq? xodim almashgan?)
 * noma'lum qolardi.
 */
class DeviceController extends Controller
{
    public function __construct(
        private readonly AyollarAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:100'],
            'platform' => ['nullable', 'string', 'max:20'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);

        $staff = $this->access->staffFor($request->user());

        if ($staff === null) {
            // Doira yozuvi yo'q — qurilmani biriktirib bo'lmaydi.
            // Bu xato EMAS: veb brauzerdan kirgan foydalanuvchida
            // `staff` bo'lmasligi normal.
            return response()->json(['registered' => false]);
        }

        $previous = $staff->last_device_id;

        $staff->update([
            'last_device_id' => $data['device_id'],
            'last_platform' => $data['platform'] ?? null,
            'last_app_version' => $data['app_version'] ?? null,
            'last_seen_at' => now(),
        ]);

        // Qurilma ALMASHGANI jurnalga tushadi. Bir xil qurilmadan
        // har kirish yozilsa, jurnal shovqinga to'lardi va haqiqiy
        // hodisa (planshet almashdi) ko'rinmay qolardi.
        if ($previous !== null && $previous !== $data['device_id']) {
            $this->audit->log(
                $request->user(),
                'device.changed',
                'staff',
                (string) $staff->id,
                ['dan' => $previous, 'ga' => $data['device_id']],
                $request,
            );
        }

        return response()->json(['registered' => true]);
    }
}
