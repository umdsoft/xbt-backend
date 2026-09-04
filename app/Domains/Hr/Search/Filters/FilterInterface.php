<?php

declare(strict_types=1);

namespace App\Domains\Hr\Search\Filters;

use App\Domains\Hr\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * SOLID-O: Ҳар бир қидирув фильтри шу интерфейсни имплемент қилади.
 * Янги фильтр қўшиш = янги класс яратиш. Мавжуд кодга тегилмайди.
 */
interface FilterInterface
{
    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function apply(Builder $query, mixed $value): Builder;
}
