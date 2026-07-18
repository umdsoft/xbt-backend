<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADMIN — yangi operatsion (deputat) user yaratish validatsiyasi.
 * Ruxsat (admin) route middleware'da (`mahalla.admin`) tekshiriladi.
 */
class StoreMahallaUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Login markaziy identifikatsiyada yagona bo'lishi shart.
            'login' => ['required', 'string', 'max:100', 'unique:auth.users,login'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'mahalla_id' => ['nullable', 'uuid', 'exists:master.mahallas,id'],
            'street_ids' => ['array'],
            'street_ids.*' => ['uuid', 'distinct', 'exists:master.streets,id'],
        ];
    }
}
