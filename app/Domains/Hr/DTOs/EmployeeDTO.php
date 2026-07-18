<?php

declare(strict_types=1);

namespace App\Domains\Hr\DTOs;

class EmployeeDTO
{
    public function __construct(
        // 1-блок: Сарлавҳа
        public readonly string $last_name_cyr,
        public readonly string $first_name_cyr,
        public readonly string $middle_name_cyr,
        public readonly ?string $last_name_lat,
        public readonly ?string $first_name_lat,
        public readonly ?string $middle_name_lat,
        public readonly string $current_position,
        public readonly ?string $position_start_date,
        public readonly ?string $photo_path,
        // 2-блок: Шахсий маълумотлар
        public readonly string $birth_date,
        public readonly ?string $birth_place,
        public readonly string $birth_region_id,
        public readonly string $birth_district_id,
        public readonly string $nationality,
        public readonly string $party_affiliation,
        public readonly string $education_level,
        public readonly string $education_completion,
        public readonly string $specialty_by_education,
        public readonly string $academic_degree,
        public readonly string $academic_title,
        public readonly string $foreign_languages,
        public readonly string $state_awards,
        public readonly string $elected_body_member,
        // Махфий
        public readonly ?string $jshshir,
        public readonly ?string $passport_series,
        public readonly ?string $passport_number,
        // Хизмат
        public readonly ?string $department_id,
        public readonly ?string $position_id,
    ) {}

    /**
     * Validated request ma'lumotlaridan DTO yaratish.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            last_name_cyr: $data['last_name_cyr'],
            first_name_cyr: $data['first_name_cyr'],
            middle_name_cyr: $data['middle_name_cyr'],
            last_name_lat: $data['last_name_lat'] ?? null,
            first_name_lat: $data['first_name_lat'] ?? null,
            middle_name_lat: $data['middle_name_lat'] ?? null,
            current_position: $data['current_position'],
            position_start_date: $data['position_start_date'] ?? null,
            photo_path: $data['photo_path'] ?? null,
            birth_date: $data['birth_date'],
            birth_place: $data['birth_place'] ?? null,
            birth_region_id: (string) $data['birth_region_id'],
            birth_district_id: (string) $data['birth_district_id'],
            nationality: $data['nationality'],
            party_affiliation: $data['party_affiliation'] ?? 'йўқ',
            education_level: $data['education_level'],
            education_completion: $data['education_completion'],
            specialty_by_education: $data['specialty_by_education'],
            academic_degree: $data['academic_degree'] ?? 'йўқ',
            academic_title: $data['academic_title'] ?? 'йўқ',
            foreign_languages: $data['foreign_languages'] ?? 'йўқ',
            state_awards: $data['state_awards'] ?? 'тақдирланмаган',
            elected_body_member: $data['elected_body_member'] ?? 'йўқ',
            jshshir: $data['jshshir'] ?? null,
            passport_series: $data['passport_series'] ?? null,
            passport_number: $data['passport_number'] ?? null,
            department_id: isset($data['department_id']) ? (string) $data['department_id'] : null,
            position_id: isset($data['position_id']) ? (string) $data['position_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'last_name_cyr' => $this->last_name_cyr,
            'first_name_cyr' => $this->first_name_cyr,
            'middle_name_cyr' => $this->middle_name_cyr,
            'last_name_lat' => $this->last_name_lat,
            'first_name_lat' => $this->first_name_lat,
            'middle_name_lat' => $this->middle_name_lat,
            'current_position' => $this->current_position,
            'position_start_date' => $this->position_start_date,
            'photo_path' => $this->photo_path,
            'birth_date' => $this->birth_date,
            'birth_place' => $this->birth_place,
            'birth_region_id' => $this->birth_region_id,
            'birth_district_id' => $this->birth_district_id,
            'nationality' => $this->nationality,
            'party_affiliation' => $this->party_affiliation,
            'education_level' => $this->education_level,
            'education_completion' => $this->education_completion,
            'specialty_by_education' => $this->specialty_by_education,
            'academic_degree' => $this->academic_degree,
            'academic_title' => $this->academic_title,
            'foreign_languages' => $this->foreign_languages,
            'state_awards' => $this->state_awards,
            'elected_body_member' => $this->elected_body_member,
            'jshshir' => $this->jshshir,
            'passport_series' => $this->passport_series,
            'passport_number' => $this->passport_number,
            'department_id' => $this->department_id,
            'position_id' => $this->position_id,
        ];
    }
}
