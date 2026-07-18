<?php

declare(strict_types=1);

namespace App\Domains\Hr\Search\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Бўлим бўйича фильтр.
 */
class DepartmentFilter implements FilterInterface
{
    public function apply(Builder $query, mixed $value): Builder
    {
        return $query->where('department_id', $value);
    }
}
