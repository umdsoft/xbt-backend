<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

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
            'mahalla_id' => ['nullable', 'uuid', 'exists:master.mahallas,id'],
            'street_ids' => ['array'],
            'street_ids.*' => ['uuid', 'distinct', 'exists:master.streets,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
