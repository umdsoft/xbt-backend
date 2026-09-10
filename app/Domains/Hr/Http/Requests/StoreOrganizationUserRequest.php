<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreOrganizationUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Asosiy avtorizatsiya OrganizationPolicy::manageUsers orqali (controllerda).
        return app(\App\Domains\Hr\Support\HrAccess::class)->can('tashkilot.manage-users') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'login' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/', 'unique:users,login'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            // Faqat tashkilot rollari
            'role' => ['required', 'string', 'in:tashkilot-admin,tashkilot-xodimi'],
        ];
    }
}
