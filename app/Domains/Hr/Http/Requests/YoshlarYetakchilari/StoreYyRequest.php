<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests\YoshlarYetakchilari;

use Illuminate\Foundation\Http\FormRequest;

class StoreYyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Domains\Hr\Support\HrAccess::class)->can('yoshlar.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'string', 'exists:users,id'],
            'mahalla_id' => ['nullable', 'string', 'exists:mahallas,id'],
            'full_name_cyr' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
