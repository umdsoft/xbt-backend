<?php

declare(strict_types=1);

namespace App\Domains\Hr\Exports;

use App\Domains\Hr\Models\Employee;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * TT бўлим 7 — Маълумотнома .docx экспорти.
 *
 * Template: resources/templates/malumotnoma.docx
 * TemplateProcessor орқали placeholder ларни алмаштиради.
 */
class MalumotnomaDocxExporter
{
    public function __construct(
        private EmployeeFormatter $formatter,
    ) {}

    public function export(Employee $employee): string
    {
        $data = $this->formatter->format($employee);
        $templatePath = resource_path('templates/malumotnoma.docx');

        $tp = new TemplateProcessor($templatePath);

        // ===== Оддий майдонлар =====
        $tp->setValue('full_name', $this->escape($data['full_name']));
        $tp->setValue('position_start_date', $this->escape($data['position_start_date']));
        $tp->setValue('current_position', $this->escape($data['current_position']));
        $tp->setValue('birth_date', $this->escape($data['birth_date']));

        // Туғилган жойи
        $birthPlace = trim(($data['birth_region'] ?? '').', '.($data['birth_district'] ?? ''), ', ');
        if (! empty($data['birth_place'])) {
            $birthPlace = $data['birth_place'];
        }
        $tp->setValue('birth_place', $this->escape($birthPlace));

        $tp->setValue('nationality', $this->escape($data['nationality']));
        $tp->setValue('party_affiliation', $this->escape($data['party_affiliation']));
        $tp->setValue('education_level', $this->escape($data['education_level']));
        $tp->setValue('education_completion', $this->escape($data['education_completion']));
        $tp->setValue('specialty_by_education', $this->escape($data['specialty_by_education']));
        $tp->setValue('academic_degree', $this->escape($data['academic_degree']));
        $tp->setValue('academic_title', $this->escape($data['academic_title']));
        $tp->setValue('foreign_languages', $this->escape($data['foreign_languages']));
        $tp->setValue('state_awards', $this->escape($data['state_awards']));
        $tp->setValue('elected_body_member', $this->escape($data['elected_body_member']));

        // ===== Расм ===== (МАХФИЙ private/local диск)
        $photoPath = ($employee->photo_path !== null && $employee->photo_path !== '')
            ? Storage::disk('local')->path($employee->photo_path)
            : null;

        if ($photoPath && file_exists($photoPath)) {
            $tp->setImageValue('photo', [
                'path' => $photoPath,
                'width' => 90,
                'height' => 120,
            ]);
        } else {
            $tp->setValue('photo', '');
        }

        // ===== Меҳнат фаолияти (оддий матн — жадвал эмас) =====
        /** @var array<int, array{years: string, description: string}> $workHistory */
        $workHistory = $data['work_history'];

        $workLines = [];
        foreach ($workHistory as $item) {
            $workLines[] = $this->escape($item['years']).' - '.$this->escape($item['description']);
        }

        // Бир placeholder ни кўп қаторли матн билан алмаштириш
        $workText = count($workLines) > 0
            ? implode('</w:t><w:br/><w:t>', $workLines)
            : '';

        $tp->setValue('work_years', $workText);
        $tp->setValue('work_description', '');

        // ===== Яқин қариндошлар (жадвал қатори) =====
        /** @var array<int, array{relationship: string, full_name: string, birth_year_place: string, workplace: string, residence: string}> $relatives */
        $relatives = $data['relatives'];
        $relCount = count($relatives);

        if ($relCount > 0) {
            $tp->cloneRow('rel_type', $relCount);
            foreach ($relatives as $i => $rel) {
                $n = $i + 1;
                $tp->setValue("rel_type#{$n}", $this->escape($rel['relationship']));
                $tp->setValue("rel_name#{$n}", $this->escape($rel['full_name']));
                $tp->setValue("rel_birth#{$n}", $this->escape($rel['birth_year_place']));
                $tp->setValue("rel_work#{$n}", $this->escape($rel['workplace']));
                $tp->setValue("rel_address#{$n}", $this->escape($rel['residence']));
            }
        } else {
            $tp->cloneRow('rel_type', 0);
        }

        // Файлни УНИКАЛ номга сақлаш (M8) — бир вақтда бир хил ходим эксппорти
        // тўқнашмаслиги учун. Чиройли юклаш номи ExportController да алоҳида берилади.
        $path = storage_path('app/private/malumotnoma_'.Str::uuid()->toString().'.docx');
        $tp->saveAs($path);

        return $path;
    }

    public function generateFilename(Employee $employee): string
    {
        $date = now()->format('Y-m-d');

        return "Malumotnoma_{$employee->last_name_cyr}_{$employee->first_name_cyr}_{$date}.docx";
    }

    /**
     * XML учун махсус белгиларни escape қилиш.
     */
    private function escape(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
