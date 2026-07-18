<?php

declare(strict_types=1);

namespace App\Domains\Hr\Exports;

use App\Domains\Hr\Models\ControlPlan;
use Carbon\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;

class ControlPlanDocxExporter
{
    /** Юклаб олиш учун чиройли (тозаланган) файл номи. */
    public function downloadName(ControlPlan $plan): string
    {
        $ref = preg_replace('/[^\p{L}\p{N}_-]+/u', '-', (string) ($plan->document_number ?? $plan->id));

        return 'NazoratReja_'.$ref.'_'.now()->format('Y-m-d').'.docx';
    }

    private const FONT = 'Times New Roman';

    private const SIZE = 14;

    private const SIZE_SMALL = 12;

    public function export(ControlPlan $plan): string
    {
        $plan->load(['items.responsibles.user.position', 'items.responsibles.user.department', 'creator']);

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName(self::FONT);
        $phpWord->setDefaultFontSize(self::SIZE);

        // A4 Landscape
        $section = $phpWord->addSection([
            'orientation' => 'landscape',
            'pageSizeW' => 16838,
            'pageSizeH' => 11906,
            'marginTop' => 567,
            'marginBottom' => 567,
            'marginLeft' => 850,
            'marginRight' => 567,
        ]);

        $bold = ['bold' => true, 'size' => self::SIZE, 'name' => self::FONT];
        $normal = ['size' => self::SIZE, 'name' => self::FONT];
        $small = ['size' => self::SIZE_SMALL, 'name' => self::FONT];
        $smallBold = ['bold' => true, 'size' => self::SIZE_SMALL, 'name' => self::FONT];
        $center = ['alignment' => Jc::CENTER, 'spaceAfter' => 60];
        $left = ['spaceAfter' => 40];

        // Сарлавҳа
        $section->addText(
            htmlspecialchars($plan->title, ENT_XML1),
            $bold,
            ['alignment' => Jc::CENTER, 'spaceAfter' => 120],
        );

        // Ҳолат санаси
        if ($plan->status_date) {
            $section->addText(
                htmlspecialchars($plan->status_date, ENT_XML1),
                ['bold' => true, 'italic' => true, 'size' => self::SIZE, 'name' => self::FONT, 'color' => 'FF0000'],
                ['alignment' => Jc::END, 'spaceAfter' => 120],
            );
        }

        // Жадвал
        $table = $section->addTable([
            'borderSize' => 6,
            'borderColor' => '000000',
            'cellMargin' => 60,
            'unit' => TblWidth::TWIP,
        ]);

        // Сарлавҳа қатори
        $headerRow = $table->addRow();
        $this->addHeaderCell($headerRow, 600, 'Т/р');
        $this->addHeaderCell($headerRow, 2800, 'Чора-тадбирлар номи');
        $this->addHeaderCell($headerRow, 3200, 'Амалга оширилиш механизми');
        $this->addHeaderCell($headerRow, 1600, 'Молиялаштириш манбаси');
        $this->addHeaderCell($headerRow, 1200, 'Ижро муддати');
        $this->addHeaderCell($headerRow, 2200, 'Масъуллар');
        $this->addHeaderCell($headerRow, 3000, 'Бажарилиши');

        // Бандлар
        $execLabels = [
            'not_started' => 'Бажарилмаган.',
            'in_progress' => 'Бажарилмоқда.',
            'completed' => 'Бажарилган.',
            'overdue' => 'Муддати ўтган.',
        ];

        foreach ($plan->items as $item) {
            $row = $table->addRow();

            // Т/р
            $row->addCell(600)->addText(
                $this->esc($item->item_number).'.',
                $small,
                $center,
            );

            // Чора-тадбир номи
            $row->addCell(2800)->addText(
                $this->esc($item->task_description),
                $small,
                $left,
            );

            // Амалга оширилиш
            $implCell = $row->addCell(3200);
            if ($item->implementation) {
                $implCell->addText($this->esc($item->implementation), $small, $left);
            } else {
                $implCell->addText('—', $small, $center);
            }

            // Молиялаштириш
            $row->addCell(1600)->addText(
                $this->esc($item->funding_source ?? '—'),
                $small,
                $center,
            );

            // Ижро муддати
            $deadline = '—';
            if ($item->deadline) {
                $date = Carbon::parse($item->deadline);
                $months = [1 => 'январ', 'феврал', 'март', 'апрел', 'май', 'июн', 'июл', 'август', 'сентябр', 'октябр', 'ноябр', 'декабр'];
                $deadline = "{$date->year} йил {$months[$date->month]}";
            }
            $row->addCell(1200)->addText($deadline, $small, $center);

            // Масъуллар
            $respCell = $row->addCell(2200);
            if ($item->responsibles->count() > 0) {
                foreach ($item->responsibles as $resp) {
                    $position = $this->getDisplayPosition($resp);
                    if ($position) {
                        $respCell->addText($this->esc($position), $small, $center);
                    }
                    $respCell->addText($this->esc($resp->responsible_name), $smallBold, $center);
                }
            } else {
                $respCell->addText('—', $small, $center);
            }

            // Бажарилиши
            $execCell = $row->addCell(3000);
            $statusText = $execLabels[$item->execution_status] ?? '';

            // Ҳолат рангли
            $statusFont = match ($item->execution_status) {
                'completed' => ['bold' => true, 'size' => self::SIZE_SMALL, 'name' => self::FONT, 'color' => '008000'],
                'in_progress' => ['bold' => true, 'size' => self::SIZE_SMALL, 'name' => self::FONT, 'color' => '0000FF'],
                'overdue' => ['bold' => true, 'size' => self::SIZE_SMALL, 'name' => self::FONT, 'color' => 'FF0000'],
                default => $smallBold,
            };

            $execCell->addText($this->esc($statusText), $statusFont, $left);

            if ($item->execution_report) {
                $execCell->addText($this->esc($item->execution_report), $small, $left);
            }
        }

        // Сақлаш — УНИКАЛ ном (path-injection ва concurrent тўқнашув олдини олиш).
        // document_number фойдаланувчидан келади (/ ёки .. бўлиши мумкин) — файл номига қўшмаймиз.
        $path = storage_path('app/private/nazoratreja_'.Str::uuid()->toString().'.docx');

        $objWriter = IOFactory::createWriter($phpWord, 'Word2007');
        $objWriter->save($path);

        return $path;
    }

    private function addHeaderCell($row, int $width, string $text): void
    {
        $cell = $row->addCell($width, [
            'bgColor' => 'F0F0F0',
            'valign' => 'center',
        ]);
        $cell->addText(
            $this->esc($text),
            ['bold' => true, 'size' => self::SIZE_SMALL, 'name' => self::FONT],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 40],
        );
    }

    private function getDisplayPosition($resp): ?string
    {
        if ($resp->responsible_position) {
            return $resp->responsible_position;
        }

        $user = $resp->user;
        if (! $user || ! $user->position) {
            return null;
        }

        $dept = $user->department;
        $pos = $user->position;

        if ($dept && $dept->parent_id === null) {
            $hokimNomi = str_replace('ҳокимлиги', 'ҳокимининг', $dept->name_cyr);
            $lavozim = mb_strtolower($pos->name_cyr);
            $lavozim = preg_replace('/^ҳоким\s*/u', '', $lavozim);

            return "{$hokimNomi} {$lavozim}";
        }

        return $pos->name_cyr;
    }

    private function esc(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
