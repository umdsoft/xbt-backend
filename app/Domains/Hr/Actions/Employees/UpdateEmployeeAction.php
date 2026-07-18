<?php

declare(strict_types=1);

namespace App\Domains\Hr\Actions\Employees;

use App\Domains\Hr\DTOs\EmployeeDTO;
use App\Domains\Hr\Models\Employee;
use App\Domains\Hr\Repositories\Contracts\EmployeeRepositoryInterface;

class UpdateEmployeeAction
{
    public function __construct(
        private EmployeeRepositoryInterface $repository,
    ) {}

    public function execute(string $id, EmployeeDTO $dto): Employee
    {
        return $this->repository->update($id, $dto);
    }
}
