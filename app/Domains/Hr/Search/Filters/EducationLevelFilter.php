<?php

declare(strict_types=1);

namespace App\Domains\Hr\Search\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Маълумот даражаси бўйича фильтр (олий, ўрта махсус ва ҳ.к.).
 */
class EducationLevelFilter implements FilterInterface
{
    public function apply(Builder $query, mixed $value): Builder
    {
        return $query->where('education_level', (string) $value);
    }
}
