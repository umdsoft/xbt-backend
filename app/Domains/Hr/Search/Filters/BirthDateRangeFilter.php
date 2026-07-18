<?php

declare(strict_types=1);

namespace App\Domains\Hr\Search\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Туғилган сана оралиғи бўйича фильтр.
 * Кутилган формат: ['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD']
 */
class BirthDateRangeFilter implements FilterInterface
{
    public function apply(Builder $query, mixed $value): Builder
    {
        $range = (array) $value;

        if (! empty($range['from'])) {
            $query->where('birth_date', '>=', $range['from']);
        }

        if (! empty($range['to'])) {
            $query->where('birth_date', '<=', $range['to']);
        }

        return $query;
    }
}
