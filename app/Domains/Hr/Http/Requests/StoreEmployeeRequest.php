<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests;

use App\Domains\Hr\Services\ValidationRulesService;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Domains\Hr\Support\HrAccess::class)->can('kadrlar.create') ?? false;
    }

    public function rules(): array
    {
        $service = app(ValidationRulesService::class);
        $rules = $service->employeeRules();

        // 3-блок: Меҳнат фаолияти (ихтиёрий) — ServiceRules dan keladi, lekin "required" emas
        $rules['work_history'] = ['nullable', 'array'];
        foreach ($service->workHistoryItemRules() as $field => $itemRule) {
            $rules["work_history.*.{$field}"] = $itemRule;
        }

        // 4-блок: Яқин қариндошлар (ихтиёрий)
        $rules['relatives'] = ['nullable', 'array'];
        foreach ($service->relativesItemRules() as $field => $itemRule) {
            $rules["relatives.*.{$field}"] = $itemRule;
        }

        return $rules;
    }
}
