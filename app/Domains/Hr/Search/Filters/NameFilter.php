<?php

declare(strict_types=1);

namespace App\Domains\Hr\Search\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Ф.И.Ш. бўйича қидирув (Кирилл ва Лотин).
 */
class NameFilter implements FilterInterface
{
    public function apply(Builder $query, mixed $value): Builder
    {
        $search = (string) $value;

        // whereLike (caseSensitive=false) — PostgreSQL'да ILIKE, MySQL/SQLite'да LIKE.
        // pg_trgm GIN индекси билан катта базада ҳам тез ишлайди.
        return $query->where(function (Builder $q) use ($search) {
            $q->whereLike('last_name_cyr', "%{$search}%")
                ->orWhereLike('first_name_cyr', "%{$search}%")
                ->orWhereLike('middle_name_cyr', "%{$search}%")
                ->orWhereLike('last_name_lat', "%{$search}%")
                ->orWhereLike('first_name_lat', "%{$search}%");
        });
    }
}
