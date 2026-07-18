<?php

declare(strict_types=1);

namespace App\Domains\Hr\Search\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * SOLID-O: Ҳар бир қидирув фильтри шу интерфейсни имплемент қилади.
 * Янги фильтр қўшиш = янги класс яратиш. Мавжуд кодга тегилмайди.
 */
interface FilterInterface
{
    /**
     * @param  Builder<\App\Domains\Hr\Models\Employee>  $query
     * @param  mixed  $value
     * @return Builder<\App\Domains\Hr\Models\Employee>
     */
    public function apply(Builder $query, mixed $value): Builder;
}
