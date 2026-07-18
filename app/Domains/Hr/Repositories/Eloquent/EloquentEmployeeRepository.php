<?php

declare(strict_types=1);

namespace App\Domains\Hr\Repositories\Eloquent;

use App\Domains\Hr\DTOs\EmployeeDTO;
use App\Domains\Hr\Models\Employee;
use App\Domains\Hr\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Domains\Hr\Search\EmployeeSearchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class EloquentEmployeeRepository implements EmployeeRepositoryInterface
{
    public function __construct(
        private EmployeeSearchService $searchService,
    ) {}

    public function find(string $id): ?Employee
    {
        return Employee::with(['department', 'position', 'birthRegion', 'birthDistrict'])->find($id);
    }

    public function findByUuid(string $uuid): ?Employee
    {
        return Employee::with(['department', 'position', 'birthRegion', 'birthDistrict'])
            ->where('uuid', $uuid)
            ->first();
    }

    public function create(EmployeeDTO $dto): Employee
    {
        $data = $dto->toArray();
        $data['uuid'] = Str::uuid()->toString();

        return Employee::create($data);
    }

    public function update(string $id, EmployeeDTO $dto): Employee
    {
        $employee = Employee::findOrFail($id);
        $employee->update($dto->toArray());

        /** @var Employee */
        return $employee->fresh(['department', 'position', 'birthRegion', 'birthDistrict']);
    }

    public function delete(string $id): bool
    {
        $employee = Employee::findOrFail($id);

        return (bool) $employee->delete();
    }

    public function restore(string $id): bool
    {
        $employee = Employee::withTrashed()->findOrFail($id);

        return $employee->restore();
    }

    public function forceDelete(string $id): bool
    {
        $employee = Employee::withTrashed()->findOrFail($id);

        return (bool) $employee->forceDelete();
    }

    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->searchService->search($filters, $perPage);
    }
}
