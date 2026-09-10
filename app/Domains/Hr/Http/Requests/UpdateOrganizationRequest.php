<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Asosiy avtorizatsiya OrganizationPolicy::update orqali (authorizeResource).
        return app(\App\Domains\Hr\Support\HrAccess::class)->can('tashkilotlar.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name_cyr' => ['required', 'string', 'max:255'],
            'name_lat' => ['nullable', 'string', 'max:255'],
            'inn' => ['nullable', 'string', 'regex:/^\d{9}$/'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'kompleks_id' => ['nullable', 'string', 'exists:departments,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
