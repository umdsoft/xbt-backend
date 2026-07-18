<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services;

use App\Domains\Hr\Models\District;
use App\Domains\Hr\Models\Employee;
use App\Domains\Hr\Validators\NoAbbreviationValidator;
use App\Domains\Hr\Validators\NoInitialsValidator;
use Closure;

/**
 * DRY: Барча валидация қоидалари битта жойда.
 * FormRequest, Import, API — ҳаммаси шу сервисдан фойдаланади.
 */
class ValidationRulesService
{
    /**
     * Рухсат этилган қариндошлик турлари (ENUM).
     *
     * @var array<string>
     */
    public const RELATIONSHIP_TYPES = [
        'Отаси', 'Онаси', 'Опаси', 'Синглиси', 'Акаси', 'Укаси',
        'Турмуш ўртоғи', 'Ўғли', 'Қизи', 'Қайнотаси', 'Қайнонаси',
        'Қайнукаси', 'Қайнсинглиси', 'Невараси', 'Келини', 'Куёви',
    ];

    /**
     * Рухсат этилган маълумот даражалари (ENUM).
     *
     * @var array<string>
     */
    public const EDUCATION_LEVELS = [
        'олий', 'тугалланмаган олий', 'ўрта махсус', 'ўрта',
    ];

    /**
     * Employee yaratish/yangilash uchun validatsiya qoidalari.
     *
     * @param  string|null  $excludeId  Yangilashda joriy employee ID (unique tekshiruv uchun)
     * @return array<string, mixed>
     */
    public function employeeRules(?string $excludeId = null): array
    {
        // ЖШШИР уникаллиги: шифрланган устунга unique ишламайди (ноаниқ шифрматн),
        // шунинг учун деттерминистик jshshir_hash бўйича текширамиз (барча tenant'лар бўйича).
        // Мутлақо муҳим: SoftDeletes global scope'ни ҳам четлаб ўтамиз (withTrashed) —
        // акс ҳолда архивдаги (soft-deleted) ходим валидацияга кўринмайди, лекин DB
        // unique индексида қолади → қайта рўйхатга олишда 500 (SQLSTATE 23000).
        $uniqueJshshir = function (string $attribute, mixed $value, Closure $fail) use ($excludeId): void {
            if ($value === null || $value === '') {
                return;
            }

            $hash = Employee::hashJshshir((string) $value);

            $match = Employee::withoutTenantScope()
                ->withTrashed()
                ->where('jshshir_hash', $hash)
                ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
                ->first();

            if ($match === null) {
                return;
            }

            $fail($match->trashed()
                ? 'Бу ЖШШИР архивдаги (ўчирилган) ходимга тегишли. Уни қайта тиклаш керак.'
                : 'Бу ЖШШИР аллақачон рўйхатдан ўтган.');
        };

        return [
            // 1-блок: Сарлавҳа
            'last_name_cyr' => ['required', 'string', 'max:50', new NoAbbreviationValidator],
            'first_name_cyr' => ['required', 'string', 'max:50'],
            'middle_name_cyr' => ['required', 'string', 'max:50'],
            'last_name_lat' => ['nullable', 'string', 'max:50'],
            'first_name_lat' => ['nullable', 'string', 'max:50'],
            'middle_name_lat' => ['nullable', 'string', 'max:50'],
            'current_position' => ['required', 'string', new NoAbbreviationValidator],
            'position_start_date' => ['nullable', 'date'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            // Диққат: `photo_path` МИЖОЗ ТОМОНИДАН юборилмайди — path traversal олдини
            // олиш учун у фақат серверда, юкланган файлдан аниқланади (EmployeeController).

            // 2-блок: Шахсий маълумотлар
            'birth_date' => ['required', 'date', 'before:-16 years'],
            // Туғилган жойи — тўлиқ, қисқартиришсиз (миграция изоҳига мувофиқ)
            'birth_place' => ['nullable', 'string', 'max:255', new NoAbbreviationValidator],
            'birth_region_id' => ['required', 'string', 'exists:regions,id'],
            // Туман танланган вилоятга тегишли бўлиши шарт (иерархия бузилмаслиги учун).
            'birth_district_id' => ['required', 'string', 'exists:districts,id', function (string $attribute, mixed $value, Closure $fail): void {
                $regionId = request()->input('birth_region_id');
                if ($regionId !== null && ! District::where('id', $value)->where('region_id', $regionId)->exists()) {
                    $fail('Танланган туман танланган вилоятга тегишли эмас.');
                }
            }],
            'nationality' => ['required', 'string', 'max:50'],
            'party_affiliation' => ['required', 'string', 'max:100'],
            'education_level' => ['required', 'in:'.implode(',', self::EDUCATION_LEVELS)],
            'education_completion' => ['required', 'string', new NoAbbreviationValidator],
            'specialty_by_education' => ['required', 'string', 'max:255'],
            'academic_degree' => ['required', 'string', 'max:100'],
            'academic_title' => ['required', 'string', 'max:100'],
            'foreign_languages' => ['required', 'string'],
            'state_awards' => ['required', 'string', new NoAbbreviationValidator],
            'elected_body_member' => ['required', 'string'],

            // Махфий
            'jshshir' => ['nullable', 'string', 'size:14', $uniqueJshshir],
            'passport_series' => ['nullable', 'string', 'size:2'],
            'passport_number' => ['nullable', 'string', 'size:7'],

            // Хизмат
            'department_id' => ['nullable', 'string', 'exists:departments,id'],
            'position_id' => ['nullable', 'string', 'exists:positions,id'],
        ];
    }

    /**
     * Меҳнат фаолияти ёзуви учун валидация қоидалари.
     *
     * @return array<string, mixed>
     */
    public function workHistoryRules(): array
    {
        $rules = ['work_history' => ['required', 'array', 'min:1']];
        foreach ($this->workHistoryItemRules() as $field => $itemRule) {
            $rules["work_history.*.{$field}"] = $itemRule;
        }

        return $rules;
    }

    /**
     * Bitta work_history yozuvi uchun field-level qoidalar (DRY).
     *
     * @return array<string, mixed>
     */
    public function workHistoryItemRules(): array
    {
        return [
            'start_year' => ['required', 'integer', 'min:1950', 'max:'.date('Y')],
            'end_year' => ['nullable', 'integer', 'min:1950', 'max:'.date('Y')],
            'organization_full' => ['required', 'string', new NoAbbreviationValidator],
            'position_full' => ['required', 'string', new NoAbbreviationValidator],
            'order_number' => ['nullable', 'string', 'max:50'],
            'order_date' => ['nullable', 'date'],
        ];
    }

    /**
     * Яқин қариндошлар ёзуви учун валидация қоидалари.
     *
     * @return array<string, mixed>
     */
    public function relativesRules(): array
    {
        $rules = ['relatives' => ['required', 'array', 'min:1']];
        foreach ($this->relativesItemRules() as $field => $itemRule) {
            $rules["relatives.*.{$field}"] = $itemRule;
        }

        return $rules;
    }

    /**
     * Bitta relative yozuvi uchun field-level qoidalar (DRY).
     *
     * @return array<string, mixed>
     */
    public function relativesItemRules(): array
    {
        return [
            'relationship_type' => ['required', 'in:'.implode(',', self::RELATIONSHIP_TYPES)],
            'full_name_cyr' => ['required', 'string', 'max:255', new NoAbbreviationValidator, new NoInitialsValidator],
            'birth_year' => ['required', 'integer', 'min:1920', 'max:'.date('Y')],
            'birth_place' => ['required', 'string', 'max:255', new NoAbbreviationValidator],
            'is_deceased' => ['required', 'boolean'],
            'deceased_year' => ['nullable', 'required_if:relatives.*.is_deceased,true', 'integer', 'min:1950', 'max:'.date('Y')],
            'workplace_and_position' => ['required_if:relatives.*.is_deceased,false', 'string', new NoAbbreviationValidator],
            'former_position' => ['required_if:relatives.*.is_deceased,true', 'nullable', 'string'],
            'residence_full' => ['required', 'string', new NoAbbreviationValidator],
        ];
    }
}
