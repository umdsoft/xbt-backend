<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests;

use App\Domains\Hr\Enums\AssigneeType;
use App\Domains\Hr\Models\Organization;
use App\Domains\Hr\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Мустақил топшириқ яратиш (source=standalone), масъул — ички ходим ёки ташкилот.
 */
class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $u = app(\App\Domains\Hr\Support\HrAccess::class)->actor();

        return (bool) ($u?->can('tadbirlar.create') || $u?->can('topshiriqlar.assign-org'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:500'],
            'task_description' => ['required', 'string'],
            'implementation' => ['nullable', 'string'],
            'deadline' => ['nullable', 'date'],

            // Ixtiyoriy: asosiy hujjat(lar) va havola
            'link' => ['nullable', 'string', 'max:1000'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png'],

            // Yangi: bir nechta mas'ul (nazorat reja kabi)
            'responsibles' => ['sometimes', 'array', 'min:1'],
            'responsibles.*.assignee_type' => ['required_with:responsibles', 'in:'.AssigneeType::values()],
            'responsibles.*.assignee_id' => ['required_with:responsibles', 'string'],
            'responsibles.*.responsible_name' => ['nullable', 'string', 'max:255'],
            'responsibles.*.responsible_position' => ['nullable', 'string', 'max:255'],
            'responsibles.*.is_primary' => ['boolean'],

            // Eski format (orqaga moslik): bitta mas'ul
            'assignee_type' => ['required_without:responsibles', 'in:'.AssigneeType::values()],
            'assignee_id' => ['required_without:responsibles', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $list = $this->resolvedResponsibles();
            if (empty($list)) {
                $v->errors()->add('responsibles', 'Камида битта масъул танланг.');

                return;
            }
            foreach ($list as $r) {
                $type = $r['assignee_type'] ?? null;
                $id = (string) ($r['assignee_id'] ?? '');

                if ($type === AssigneeType::ORGANIZATION->value) {
                    if (! app(\App\Domains\Hr\Support\HrAccess::class)->can('topshiriqlar.assign-org')) {
                        $v->errors()->add('responsibles', 'Топшириқни ташкилотга бириктириш ҳуқуқингиз йўқ.');
                    } elseif (! Organization::whereKey($id)->exists()) {
                        $v->errors()->add('responsibles', 'Танланган ташкилот топилмади.');
                    }
                } elseif ($type === AssigneeType::USER->value && ! User::whereKey($id)->exists()) {
                    $v->errors()->add('responsibles', 'Танланган ходим топилмади.');
                }
            }
        });
    }

    /**
     * Ikkala formatdan (responsibles[] yoki bitta assignee) birlashtirilgan mas'ullar ro'yxati.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolvedResponsibles(): array
    {
        $list = $this->input('responsibles');
        if (is_array($list) && count($list) > 0) {
            return array_values($list);
        }

        if ($this->filled('assignee_type') && $this->filled('assignee_id')) {
            return [[
                'assignee_type' => $this->input('assignee_type'),
                'assignee_id' => $this->input('assignee_id'),
                'is_primary' => true,
            ]];
        }

        return [];
    }
}
