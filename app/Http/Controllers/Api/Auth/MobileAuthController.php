<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Mobil (Sanctum API TOKEN) login — SPA sessiya login'idan ALOHIDA.
 * Mavjud AuthController (SPA) o'zgarmaydi.
 *
 * Tizim `system` parametri bilan tanlanadi. Berilmasa `mahalla` —
 * mahalla mobil ilovasi uni yubormaydi va o'zgarishsiz ishlashda davom etadi.
 * Ruxsat berilgan tizimlar allowlist bilan cheklangan: ixtiyoriy `system`
 * qabul qilinsa, hali mobil kanalga tayyor bo'lmagan modulga token berilardi.
 */
class MobileAuthController extends Controller
{
    /**
     * Mobil token bera oladigan tizimlar.
     *
     * @var array<string, string>
     */
    private const MOBILE_SYSTEMS = [
        'mahalla' => 'Mahalla',
        'agro' => 'AgroAI Hub',
    ];

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'system' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::MOBILE_SYSTEMS))],
        ]);

        $system = $data['system'] ?? 'mahalla';

        $user = User::where('login', $data['login'])->where('is_active', true)->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => 'Логин ёки парол нотўғри, ёки ҳисоб фаол эмас.',
            ]);
        }

        if (! $user->canAccessSystem($system)) {
            throw ValidationException::withMessages([
                'login' => self::MOBILE_SYSTEMS[$system].' тизимига рухсат йўқ.',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $token = $user->createToken($data['device_name'] ?? 'mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'login' => $user->login,
                'name' => $user->name,
                'phone' => $user->phone,
            ],
            'systems' => $user->accessibleSystems(),
        ]);
    }

    /**
     * Joriy tokenni bekor qiladi (auth:sanctum guruhida).
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['message' => 'Чиқилди']);
    }
}
