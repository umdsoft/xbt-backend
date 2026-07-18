<?php

declare(strict_types=1);

namespace App\Domains\Hr\Actions\Employees;

use App\Domains\Hr\DTOs\EmployeeDTO;
use App\Domains\Hr\Models\Employee;
use App\Domains\Hr\Repositories\Contracts\EmployeeRepositoryInterface;

class CreateEmployeeAction
{
    public function __construct(
        private EmployeeRepositoryInterface $repository,
    ) {}

    public function execute(EmployeeDTO $dto): Employee
    {
        return $this->repository->create($dto);
    }
}
