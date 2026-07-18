<?php

declare(strict_types=1);

namespace App\Domains\Hr\Repositories\Contracts;

use App\Domains\Hr\DTOs\EmployeeDTO;
use App\Domains\Hr\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EmployeeRepositoryInterface
{
    public function find(string $id): ?Employee;

    public function findByUuid(string $uuid): ?Employee;

    public function create(EmployeeDTO $dto): Employee;

    public function update(string $id, EmployeeDTO $dto): Employee;

    public function delete(string $id): bool;

    public function restore(string $id): bool;

    public function forceDelete(string $id): bool;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator;
}
