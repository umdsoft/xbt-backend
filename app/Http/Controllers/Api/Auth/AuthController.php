<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
     * Joriy foydalanuvchi o'z parolini o'zgartiradi (MARKAZIY — barcha tizimlar
     * uchun bitta endpoint: advisor/mahalla/hr/sport). Jorij parol tekshiriladi;
     * yangi parol jorijsidan farq qilishi va tasdiqlanishi shart.
     *
     * Parol o'zgargach sessiya YANGILANADI (`Auth::login` + `regenerate`) — shunda
     * `AuthenticateSession` faol bo'lsa ham foydalanuvchi tizimdan chiqmaydi.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => array_merge(['required', 'confirmed'], PasswordPolicy::rules()),
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Жорий парол нотўғри.',
            ]);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Янги парол жорий паролдан фарқ қилиши керак.',
            ]);
        }

        // Uzunlik va tarkib qoidasidan o'tadigan, lekin baribir oson
        // topiladigan parollar (login yoki mahalla nomi ichida).
        $rejected = PasswordPolicy::reject($data['password'], (string) $user->login);

        if ($rejected !== null) {
            throw ValidationException::withMessages(['password' => $rejected]);
        }

        // 'hashed' cast parolni saqlashda xeshlaydi.
        $user->forceFill([
            'password' => $data['password'],
            'password_changed_at' => now(),
        ])->save();

        /*
            JURNAL — PAROLSIZ.

            Yozilmasa, «hisobim ishlamayapti» degan murojaatda
            administratorda hech qanday iz qolmaydi: parol o'zgarganmi,
            yo'qmi — bilib bo'lmaydi. Parolning O'ZI hech qachon
            yozilmaydi, faqat o'zgarish fakti.
        */
        Log::channel('stack')->info('auth.password_changed', [
            'user_id' => $user->id,
            'login' => $user->login,
            'ip' => $request->ip(),
            'agent' => substr((string) $request->userAgent(), 0, 200),
        ]);

        // Stateful (SPA sessiya) so'rovда: yangi hash bilan qayta autentifikatsiya +
        // sessiya id'sini almashtirish — shunda foydalanuvchi tizimdan chiqmaydi.
        // Token (mobil) yoki sessiyasiz kontekstда bu bosqich o'tkazib yuboriladi.
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return response()->json(['message' => 'Парол муваффақиятли ўзгартирилди.']);
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
