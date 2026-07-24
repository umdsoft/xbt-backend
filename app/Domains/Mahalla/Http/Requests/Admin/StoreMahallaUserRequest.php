<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Requests\Admin;

use App\Domains\Mahalla\Support\MahallaAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Parol ixtiyoriy — berilmasa store()'da avtomatik login (telefon raqami)
            // qilinadi. Mahalla 5-ligi mobilда telefon raqami bilan kiradi.
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/', 'unique:auth.users,phone'],
            'mahalla_id' => ['nullable', 'uuid', 'exists:master.mahallas,id'],
            'position' => ['nullable', 'string', Rule::in(array_keys(MahallaAccess::POSITIONS))],
            'street_ids' => ['array'],
            'street_ids.*' => ['uuid', 'distinct', 'exists:master.streets,id'],
        ];
    }
}
