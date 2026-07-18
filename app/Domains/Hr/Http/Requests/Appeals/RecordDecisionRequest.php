<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests\Appeals;

use Illuminate\Foundation\Http\FormRequest;

class RecordDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Domains\Hr\Support\HrAccess::class)->can('appeals.decide') ?? false;
    }

    public function rules(): array
    {
        return [
            'council_id' => ['required', 'string', 'exists:mahalla_councils,id'],
            'meeting_date' => ['required', 'date'],
            'decision_type' => ['required', 'in:approve,reject,partial,escalate,info'],
            'decision_text' => ['required', 'string', 'min:5'],
            'voting_result' => ['nullable', 'array'],
            'decided_at' => ['nullable', 'date'],
        ];
    }
}
