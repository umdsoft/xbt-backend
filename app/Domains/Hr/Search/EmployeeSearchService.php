<?php

declare(strict_types=1);

namespace App\Domains\Hr\Search;

use App\Domains\Hr\Models\Employee;
use App\Domains\Hr\Search\Filters\BirthDateRangeFilter;
use App\Domains\Hr\Search\Filters\DepartmentFilter;
use App\Domains\Hr\Search\Filters\DistrictFilter;
use App\Domains\Hr\Search\Filters\EducationLevelFilter;
use App\Domains\Hr\Search\Filters\FilterInterface;
use App\Domains\Hr\Search\Filters\NameFilter;
use App\Domains\Hr\Search\Filters\NationalityFilter;
use App\Domains\Hr\Search\Filters\SpecialtyFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pipeline паттерни орқали қидирув (SOLID-O).
 *
 * Янги фильтр қўшиш: 1) Filter класс яратиш 2) $filterMap га қўшиш.
 * Мавжуд фильтрларга тегилмайди.
 */
class EmployeeSearchService
{
    /**
     * Параметр номи → Filter класси.
     *
     * @var array<string, class-string<FilterInterface>>
     */
    private array $filterMap = [
        'search' => NameFilter::class,
        'birth_date_range' => BirthDateRangeFilter::class,
        'birth_district_id' => DistrictFilter::class,
        'specialty' => SpecialtyFilter::class,
        'education_level' => EducationLevelFilter::class,
        'department_id' => DepartmentFilter::class,
        'nationality' => NationalityFilter::class,
    ];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function search(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = Employee::with(['department', 'position'])
            ->orderBy('last_name_cyr');

        $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * @param  Builder<Employee>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        foreach ($this->filterMap as $param => $filterClass) {
            if (! isset($filters[$param]) || $this->isEmpty($filters[$param])) {
                continue;
            }

            /** @var FilterInterface $filter */
            $filter = new $filterClass;
            $filter->apply($query, $filters[$param]);
        }
    }

    private function isEmpty(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return empty(array_filter($value));
        }

        return $value === null;
    }
}
