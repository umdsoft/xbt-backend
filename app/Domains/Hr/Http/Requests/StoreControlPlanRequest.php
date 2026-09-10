<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests;

use App\Domains\Hr\Enums\ExecutionStatus;
use Illuminate\Foundation\Http\FormRequest;

class StoreControlPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Domains\Hr\Support\HrAccess::class)->can('tadbirlar.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'document_date' => ['nullable', 'date'],
            'status_date' => ['nullable', 'string', 'max:100'],
            'items' => ['nullable', 'array'],
            'items.*.item_number' => ['required', 'string', 'max:50'],
            'items.*.section_title' => ['nullable', 'string', 'max:500'],
            'items.*.task_description' => ['nullable', 'string'],
            'items.*.implementation' => ['nullable', 'string'],
            'items.*.funding_source' => ['nullable', 'string', 'max:500'],
            'items.*.deadline' => ['nullable', 'date'],
            'items.*.execution_status' => ['nullable', 'in:'.ExecutionStatus::values()],
            'items.*.execution_report' => ['nullable', 'string'],
            'items.*.responsibles' => ['nullable', 'array'],
            // Polimorfik masъul: ички ходим (user) ёки ташкилот (organization)
            'items.*.responsibles.*.assignee_type' => ['nullable', 'in:user,organization'],
            'items.*.responsibles.*.assignee_id' => ['nullable', 'string'],
            'items.*.responsibles.*.user_id' => ['nullable', 'string', 'exists:users,id'],
            'items.*.responsibles.*.responsible_name' => ['nullable', 'string', 'max:255'],
            'items.*.responsibles.*.responsible_position' => ['nullable', 'string', 'max:255'],
            'items.*.responsibles.*.is_primary' => ['nullable', 'boolean'],
        ];
    }
}
