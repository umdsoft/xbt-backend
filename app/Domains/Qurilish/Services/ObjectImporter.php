<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Services;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectMonthlyPlan;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Models\Program;
use App\Domains\Qurilish\Models\Sector;
use App\Domains\Qurilish\Support\DeadlineParser;
use App\Domains\Qurilish\Support\OrgRegistry;
use App\Domains\Qurilish\Support\SectorClassifier;
use App\Domains\Qurilish\Support\SoatoResolver;
use App\Domains\Qurilish\Support\StageMapper;
use App\Domains\Qurilish\Support\Translit;
use App\Domains\Qurilish\Support\WorkTypeClassifier;

/**
 * Xom CSV qatorlarini `qurilish` schema'siga yozadi.
 *
 * Idempotent: `external_id` bo'yicha upsert. Manbada ID bo'lmagan obyektlar
 * (33 DXSh + 6 ta ПҚ-393) uchun sun'iy kalit `{dastur_kodi}:{varaq_qatori}`
 * ishlatiladi — u ham barqaror, chunki qator raqami manbada o'zgarmaydi.
 * Sun'iy kalitni haqiqiy reyestr ID sidan `:` belgisi ajratib turadi.
 */
class ObjectImporter
{
    /** @var array<string, string> program code -> uuid */
    private array $programs = [];

    /** @var array<string, string> sector code -> uuid */
    private array $sectors = [];

    /** @var array<string, string> sector uuid -> department org uuid */
    private array $sectorDepartments = [];

    /** @var array<int, string> */
    private array $warnings = [];

    private int $objectCount = 0;

    private int $stageCount = 0;

    private int $monthlyCount = 0;

    private int $noDistrictCount = 0;

    private int $duplicateCount = 0;

    public function __construct(
        private readonly SoatoResolver $soato,
        private readonly DeadlineParser $deadline,
        private readonly OrgRegistry $orgs,
        private readonly SectorClassifier $sectorClassifier,
        private readonly WorkTypeClassifier $workTypes,
        private readonly StageMapper $stageMapper,
    ) {}

    /**
     * @param  iterable<int, array<string, string>>  $rows  objects.csv qatorlari
     * @param  iterable<int, array<string, string>>  $monthly  monthly.csv qatorlari
     * @return array<string, mixed> hisobot
     */
    public function import(iterable $rows, iterable $monthly, int $year = 2026): array
    {
        $this->loadReference();

        /** @var array<string, string> $keyToId */
        $keyToId = [];

        foreach ($rows as $row) {
            $key = $this->externalKey($row);

            // Manbada bitta reyestr ID ikki BOSHQA-BOSHQA obyektga berilgan
            // (2505334010717007: «Урганч давлат тиббиёт институти...» 12 750 mln va
            // «Хоразм академик лицейи...» 1 310 mln). Bu manba xatosi; upsert
            // ikkinchisini birinchisining ustiga yozsa, obyekt va uning limiti
            // butunlay yo'qolardi. Shuning uchun takroriga `#qator` qo'shamiz —
            // ikkalasi ham saqlanadi va kalit barqaror bo'lib qoladi.
            if (isset($keyToId[$key])) {
                $key = $key.'#'.$row['row'];
                $this->warnings[] = "Takroriy reyestr ID: {$row['external_id']} (varaq {$row['sheet']}, "
                    ."qator {$row['row']}) — «{$key}» kaliti bilan alohida saqlandi";
                $this->duplicateCount++;
            }

            $keyToId[$key] = $this->importObject($key, $row);
        }

        foreach ($monthly as $m) {
            $objectId = $keyToId[$m['external_key']] ?? null;
            if ($objectId === null) {
                $this->warnings[] = "Oylik grafik: obyekt topilmadi ({$m['external_key']})";

                continue;
            }

            ObjectMonthlyPlan::query()->updateOrCreate(
                ['object_id' => $objectId, 'year' => $year, 'month' => (int) $m['month']],
                ['planned_amount' => (float) $m['planned_amount']],
            );
            $this->monthlyCount++;
        }

        return [
            'objects' => $this->objectCount,
            'stages' => $this->stageCount,
            'monthly' => $this->monthlyCount,
            'no_district' => $this->noDistrictCount,
            'duplicates' => $this->duplicateCount,
            'warnings' => $this->warnings,
        ];
    }

    /** @param array<string, string> $row */
    private function importObject(string $key, array $row): string
    {
        $name = trim($row['name'] ?? '');
        $sectorCode = $this->sectorClassifier->classify(
            $row['c_value'] ?? null,
            $row['group_label'] ?? null,
            $name,
        );
        $sectorId = $this->sectors[$sectorCode] ?? null;

        $districtId = $this->soato->districtIdFor(
            $row['external_id'] ?? null,
            $this->districtHint($row),
        );
        if ($districtId === null) {
            $this->noDistrictCount++;
        }

        $deadline = $this->deadline->parse($row['deadline_raw'] ?? null);

        $object = ConstructionObject::query()->updateOrCreate(
            ['external_id' => $key],
            [
                'program_id' => $this->programs[$row['program_code']] ?? null,
                'sector_id' => $sectorId,
                'district_id' => $districtId,
                'name' => $name,
                'name_lat' => Translit::toLatin($name),
                'work_type' => $this->workTypes->classify($row['work_type_label'] ?? null, $name),
                'customer_org_id' => $this->orgs->resolve($row['customer_raw'] ?? null, 'is_customer'),
                'designer_org_id' => $this->orgs->resolve($row['designer_raw'] ?? null, 'is_designer'),
                'contractor_org_id' => $this->orgs->resolve($row['contractor_raw'] ?? null, 'is_contractor'),
                'department_org_id' => $sectorId === null ? null : ($this->sectorDepartments[$sectorId] ?? null),
                'limit_amount' => $this->num($row, 'limit_amount'),
                'tender_amount' => $this->num($row, 'tender_amount'),
                'contract_amount' => $this->num($row, 'contract_amount'),
                'disbursed_amount' => $this->num($row, 'disbursed'),
                'financed_amount' => $this->num($row, 'financed'),
                'deadline_raw' => $this->nullable($row['deadline_raw'] ?? null),
                'deadline_date' => $deadline['date'],
                'deadline_year' => $deadline['year'],
                'is_carryover' => $this->num($row, 'carryover') > 0,
                'lifecycle' => $this->lifecycle($row),
                'handover_planned' => $this->num($row, 'handover_plan') > 0,
                'handover_done' => $this->num($row, 'handover_actual') > 0,
                'note' => $this->nullable($row['note'] ?? null),
                'source' => 'import',
            ],
        );

        $this->objectCount++;
        $this->syncStages($object, $row);

        return (string) $object->id;
    }

    /** @param array<string, string> $row */
    private function syncStages(ConstructionObject $object, array $row): void
    {
        $map = $this->stageMapper->map($row);
        $current = null;

        foreach (ConstructionObject::STAGES as $code) {
            $status = $map[$code]['status'];

            ObjectStage::query()->updateOrCreate(
                ['object_id' => $object->id, 'stage_code' => $code],
                ['status' => $status, 'tz_stage' => ObjectStage::TZ_STAGE[$code]],
            );
            $this->stageCount++;

            // Joriy bosqich — birinchi YOPILMAGAN bosqich (strict sequential).
            if ($current === null && ! in_array($status, ObjectStage::DONE_STATUSES, true)) {
                $current = $code;
            }
        }

        $object->forceFill(['current_stage' => $current ?? 'handover'])->save();
    }

    /**
     * Hudud uchun zaxira nom: `ПҚ-393` da `C` ustuni tuman, boshqa varaqlarda
     * guruh-sarlavha tuman (u yerda `C` — soha).
     *
     * @param  array<string, string>  $row
     */
    private function districtHint(array $row): ?string
    {
        return ($row['program_code'] ?? '') === 'pq393'
            ? $this->nullable($row['c_value'] ?? null)
            : $this->nullable($row['group_label'] ?? null);
    }

    /** @param array<string, string> $row */
    private function lifecycle(array $row): string
    {
        if ($this->num($row, 'handover_actual') > 0) {
            return 'tugallangan';
        }

        return $this->num($row, 'disbursed') > 0 ? 'jarayonda' : 'reja';
    }

    /** @param array<string, string> $row */
    private function externalKey(array $row): string
    {
        $id = trim($row['external_id'] ?? '');

        return $id !== '' ? $id : $row['program_code'].':'.$row['row'];
    }

    /** @param array<string, string> $row */
    private function num(array $row, string $key): float
    {
        $v = $row[$key] ?? '';

        return is_numeric($v) ? (float) $v : 0.0;
    }

    private function nullable(?string $v): ?string
    {
        $v = $v === null ? '' : trim($v);

        return $v === '' ? null : $v;
    }

    private function loadReference(): void
    {
        $this->programs = Program::query()->pluck('id', 'code')->all();
        $this->sectors = Sector::query()->pluck('id', 'code')->all();
        $this->sectorDepartments = Sector::query()
            ->whereNotNull('default_department_org_id')
            ->pluck('default_department_org_id', 'id')->all();
    }
}
