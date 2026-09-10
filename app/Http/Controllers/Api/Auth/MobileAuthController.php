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
 * Faqat 'mahalla' tizimiga ruxsati bor foydalanuvchi Bearer token oladi.
 * Mavjud AuthController (SPA) o'zgarmaydi.
 */
class MobileAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::where('login', $data['login'])->where('is_active', true)->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => 'Логин ёки парол нотўғри, ёки ҳисоб фаол эмас.',
            ]);
        }

        if (! $user->canAccessSystem('mahalla')) {
            throw ValidationException::withMessages([
                'login' => 'Mahalla тизимига рухсат йўқ.',
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
