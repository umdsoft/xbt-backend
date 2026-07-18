<?php

declare(strict_types=1);

namespace App\Domains\Hr\Actions\Employees;

use App\Domains\Hr\Repositories\Contracts\EmployeeRepositoryInterface;

class DeleteEmployeeAction
{
    public function __construct(
        private EmployeeRepositoryInterface $repository,
    ) {}

    /**
     * Soft delete — TT бўлим 4.4: ҳақиқий ўчириш йўқ.
     */
    public function execute(string $id): bool
    {
        return $this->repository->delete($id);
    }
}
