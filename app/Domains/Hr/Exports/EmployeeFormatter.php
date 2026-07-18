<?php

declare(strict_types=1);

namespace App\Domains\Hr\Exports;

use App\Domains\Hr\Models\Employee;
use Carbon\Carbon;

/**
 * Ходим маълумотларини Маълумотнома экспорт формати учун тайёрлайди.
 * Саналар, меҳнат фаолияти ва қариндошлар — намунадаги форматда.
 */
class EmployeeFormatter
{
    /**
     * @return array<string, mixed>
     */
    public function format(Employee $employee): array
    {
        $employee->load(['workHistory', 'relatives', 'department', 'position', 'birthRegion', 'birthDistrict']);

        return [
            // 1-блок: Сарлавҳа
            'full_name' => $employee->full_name,
            'current_position' => $employee->current_position,
            'position_start_date' => $this->formatPositionDate($employee->position_start_date),

            // 2-блок: Шахсий маълумотлар
            'birth_date' => $this->formatBirthDate($employee->birth_date),
            'birth_place' => $employee->birth_place,
            'birth_region' => $employee->birthRegion?->name_cyr,
            'birth_district' => $employee->birthDistrict?->name_cyr,
            'nationality' => $employee->nationality,
            'party_affiliation' => $employee->party_affiliation,
            'education_level' => $employee->education_level,
            'education_completion' => $employee->education_completion,
            'specialty_by_education' => $employee->specialty_by_education,
            'academic_degree' => $employee->academic_degree,
            'academic_title' => $employee->academic_title,
            'foreign_languages' => $employee->foreign_languages,
            'state_awards' => $employee->state_awards,
            'elected_body_member' => $employee->elected_body_member,

            // 3-блок: Меҳнат фаолияти
            'work_history' => $this->formatWorkHistory($employee),

            // 4-блок: Яқин қариндошлар
            'relatives' => $this->formatRelatives($employee),
        ];
    }

    /**
     * «2007 йил 25 октябрдан» формати.
     */
    private function formatPositionDate(?Carbon $date): string
    {
        if (! $date) {
            return '';
        }

        $months = [
            1 => 'январдан', 2 => 'февралдан', 3 => 'мартдан',
            4 => 'апрелдан', 5 => 'майдан', 6 => 'июндан',
            7 => 'июлдан', 8 => 'августдан', 9 => 'сентябрдан',
            10 => 'октябрдан', 11 => 'ноябрдан', 12 => 'декабрдан',
        ];

        return "{$date->year} йил {$date->day} {$months[$date->month]}";
    }

    /**
     * «1976 йил 15 март, Хоразм вилояти, Урганч шаҳри» формати.
     */
    private function formatBirthDate(?Carbon $date): string
    {
        if (! $date) {
            return '';
        }

        $months = [
            1 => 'январ', 2 => 'феврал', 3 => 'март',
            4 => 'апрел', 5 => 'май', 6 => 'июн',
            7 => 'июл', 8 => 'август', 9 => 'сентябр',
            10 => 'октябр', 11 => 'ноябр', 12 => 'декабр',
        ];

        return "{$date->year} йил {$date->day} {$months[$date->month]}";
    }

    /**
     * Меҳнат фаолияти — «1977-1982 йй. — Урганч давлат университети, талаба» формати.
     *
     * @return array<int, array{years: string, description: string}>
     */
    private function formatWorkHistory(Employee $employee): array
    {
        $items = [];

        foreach ($employee->workHistory as $wh) {
            $endYear = $wh->end_year ? (string) $wh->end_year : 'ҳ.в.';
            $years = "{$wh->start_year}-{$endYear} йй.";

            $items[] = [
                'years' => $years,
                'description' => "{$wh->organization_full}, {$wh->position_full}",
            ];
        }

        return $items;
    }

    /**
     * Қариндошлар жадвали учун форматланган маълумотлар.
     *
     * @return array<int, array{relationship: string, full_name: string, birth_year_place: string, workplace: string, residence: string}>
     */
    private function formatRelatives(Employee $employee): array
    {
        $items = [];

        foreach ($employee->relatives as $rel) {
            $items[] = [
                'relationship' => $rel->relationship_type,
                'full_name' => $rel->full_name_cyr,
                'birth_year_place' => "{$rel->birth_year} йил, {$rel->birth_place}",
                'workplace' => $rel->workplace_and_position,
                'residence' => $rel->residence_full,
            ];
        }

        return $items;
    }
}
