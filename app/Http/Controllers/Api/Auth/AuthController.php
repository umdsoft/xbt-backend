<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Markaziy identifikatsiya API (Sanctum SPA — sessiya-asosli).
 * Frontend (Vue) oldin GET /sanctum/csrf-cookie, so'ng POST /api/login.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('login', $data['login'])->where('is_active', true)->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => 'Логин ёки парол нотўғри, ёки ҳисоб фаол эмас.',
            ]);
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return response()->json($this->userPayload($user));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    /**
     * `login` nomli route — SPA sahifasi emas, toza JSON 401.
     *
     * Guest so'rov himoyalangan route'ga urilganda `Authenticate` middleware
     * `route('login')`ni chaqiradi; bu nom aniqlanmasa 500 ("Route [login] not
     * defined") beriladi. Shu action nomni ta'minlaydi (va closure emas —
     * `route:cache` bilan mos). Frontend'lar login'ni o'z SPA'sida bajaradi.
     */
    public function showLogin(): JsonResponse
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Чиқилди']);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'user' => [
                'id' => $user->id,
                'login' => $user->login,
                'name' => $user->name,
                'phone' => $user->phone,
            ],
            'systems' => $user->accessibleSystems(),
        ];
    }
}
