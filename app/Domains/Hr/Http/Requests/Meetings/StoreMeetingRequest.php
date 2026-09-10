<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests\Meetings;

use Illuminate\Foundation\Http\FormRequest;

class StoreMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Domains\Hr\Support\HrAccess::class)->can('meetings.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'mahalla_id' => ['nullable', 'string', 'exists:mahallas,id'],
            'chairman_id' => ['required', 'string', 'exists:users,id'],
            'meeting_date' => ['required', 'date'],
            'meeting_time' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:500'],
            'participants_count' => ['nullable', 'integer', 'min:0'],
            'agenda' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:planned,completed,cancelled'],
        ];
    }
}
