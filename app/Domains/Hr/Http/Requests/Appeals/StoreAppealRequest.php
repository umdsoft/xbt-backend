<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests\Appeals;

use App\Domains\Hr\Enums\AppealPriority;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Domains\Hr\Support\HrAccess::class)->can('appeals.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'mahalla_id' => ['nullable', 'string', 'exists:mahallas,id'],
            'youth_meeting_id' => ['nullable', 'string', 'exists:youth_meetings,id'],
            'applicant_name' => ['required', 'string', 'max:255'],
            'applicant_phone' => ['nullable', 'string', 'max:30'],
            'applicant_jshshir' => ['nullable', 'string', 'size:14'],
            'applicant_birth_date' => ['nullable', 'date'],
            'applicant_address' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'min:10'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'category_id' => ['nullable', 'string', 'exists:appeal_categories,id'],
            'sub_category_id' => ['nullable', 'string', 'exists:appeal_categories,id'],
            'priority' => ['nullable', 'in:'.AppealPriority::values()],
            'source' => ['nullable', 'in:web,telegram,voice,paper,meeting'],
        ];
    }
}
