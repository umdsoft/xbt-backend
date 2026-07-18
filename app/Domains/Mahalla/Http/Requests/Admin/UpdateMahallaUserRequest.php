<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Requests\Admin;

use App\Domains\Mahalla\Support\MahallaAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADMIN — operatsion (deputat) user yangilash validatsiyasi.
 * Login o'zgartirilmaydi; parol ixtiyoriy (berilsa yangilanadi).
 */
class UpdateMahallaUserRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/', Rule::unique('auth.users', 'phone')->ignore($this->route('id'), 'id')],
            'mahalla_id' => ['nullable', 'uuid', 'exists:master.mahallas,id'],
            'position' => ['nullable', 'string', Rule::in(array_keys(MahallaAccess::POSITIONS))],
            'street_ids' => ['array'],
            'street_ids.*' => ['uuid', 'distinct', 'exists:master.streets,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
