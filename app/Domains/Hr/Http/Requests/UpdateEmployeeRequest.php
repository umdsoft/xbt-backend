<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests;

use App\Domains\Hr\Models\Employee;
use App\Domains\Hr\Services\ValidationRulesService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
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
        // Route-model binding'да {employee} — Employee модели; акс ҳолда хом id.
        $route = $this->route('employee');
        $employeeId = $route instanceof Employee ? $route->getKey() : (string) $route;

        return app(ValidationRulesService::class)->employeeRules($employeeId);
    }
}
