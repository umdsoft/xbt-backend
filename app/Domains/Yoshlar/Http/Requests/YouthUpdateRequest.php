<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Requests;

use App\Domains\Yoshlar\Models\Youth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class YouthUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'last_name' => ['sometimes', 'string', 'max:120'],
            'first_name' => ['sometimes', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'birth_date' => ['sometimes', 'date', 'before:today'],
            'gender' => ['sometimes', Rule::in(['erkak', 'ayol'])],
            'district_id' => ['sometimes', 'uuid'],
            'mahalla_id' => ['sometimes', 'uuid'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'pinfl' => ['nullable', 'string', 'size:14'],
            'passport_series' => ['nullable', 'string', 'max:10'],
            'passport_number' => ['nullable', 'string', 'max:20'],
            'education_status' => ['sometimes', Rule::in(Youth::EDUCATION_STATUSES)],
            'education_place' => ['nullable', 'string', 'max:300'],
            'employment_status' => ['sometimes', Rule::in(Youth::EMPLOYMENT_STATUSES)],
            'workplace' => ['nullable', 'string', 'max:300'],
            'registry_status' => ['sometimes', Rule::in(Youth::REGISTRY_STATUSES)],
            'is_neet' => ['boolean'],
            'is_graduate_unemployed' => ['boolean'],
            'in_youth_book' => ['boolean'],
            'is_entrepreneur' => ['boolean'],
        ];
    }
}
