<?php

declare(strict_types=1);

namespace App\Domains\Hr\Search\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Мутахассислик бўйича фильтр.
 */
class SpecialtyFilter implements FilterInterface
{
    public function apply(Builder $query, mixed $value): Builder
    {
        // caseSensitive=false — PostgreSQL'да ILIKE.
        return $query->whereLike('specialty_by_education', "%{$value}%");
    }
}
