<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Requests;

use App\Domains\Yoshlar\Models\Youth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class YouthStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // ruxsat kontrollerda YoshlarAccess orqali tekshiriladi
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:120'],
            'first_name' => ['required', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'birth_date' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(['erkak', 'ayol'])],
            'district_id' => ['required', 'uuid'],
            'mahalla_id' => ['required', 'uuid'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'pinfl' => ['nullable', 'string', 'size:14'],
            'passport_series' => ['nullable', 'string', 'max:10'],
            'passport_number' => ['nullable', 'string', 'max:20'],
            'education_status' => ['required', Rule::in(Youth::EDUCATION_STATUSES)],
            'education_place' => ['nullable', 'string', 'max:300'],
            'employment_status' => ['required', Rule::in(Youth::EMPLOYMENT_STATUSES)],
            'workplace' => ['nullable', 'string', 'max:300'],
            'is_neet' => ['boolean'],
            'is_graduate_unemployed' => ['boolean'],
            'in_youth_book' => ['boolean'],
            'is_entrepreneur' => ['boolean'],
        ];
    }
}
