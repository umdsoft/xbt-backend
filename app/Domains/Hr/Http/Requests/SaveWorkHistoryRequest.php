<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests;

use App\Domains\Hr\Services\ValidationRulesService;
use Illuminate\Foundation\Http\FormRequest;

class SaveWorkHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(\App\Domains\Hr\Support\HrAccess::class)->can('kadrlar.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return app(ValidationRulesService::class)->workHistoryRules();
    }
}
