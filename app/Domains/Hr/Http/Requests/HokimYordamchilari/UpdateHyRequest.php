<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests\HokimYordamchilari;

use App\Domains\Hr\Enums\HyDirection;
use Illuminate\Foundation\Http\FormRequest;

class UpdateHyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Domains\Hr\Support\HrAccess::class)->can('hokim-yordamchilari.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'string', 'exists:users,id'],
            'full_name_cyr' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'direction' => ['required', 'in:'.HyDirection::values()],
            'mahalla_id' => ['nullable', 'string', 'exists:mahallas,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
