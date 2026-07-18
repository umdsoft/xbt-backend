<?php

declare(strict_types=1);

namespace App\Domains\Hr\Actions\Relatives;

use App\Domains\Hr\Models\Employee;
use App\Domains\Hr\Services\DeceasedFormatterService;
use Illuminate\Support\Facades\DB;

/**
 * Яқин қариндошлар ёзувларини сақлаш (sync).
 * Вафот этган қариндош учун иш жойи автоматик форматланади.
 */
class SaveRelativesAction
{
    public function __construct(
        private DeceasedFormatterService $deceasedFormatter,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function execute(Employee $employee, array $items): void
    {
        // delete-then-create — транзаксияда: ўрта йўлда хато бўлса мавжуд
        // ёзувлар йўқолиб қолмаслиги учун (rollback).
        DB::transaction(function () use ($employee, $items): void {
            $employee->relatives()->delete();

            foreach ($items as $item) {
                // Вафот этган — автоматик формат
                if (! empty($item['is_deceased'])) {
                    $workplaceAndPosition = $this->deceasedFormatter->format(
                        (int) $item['deceased_year'],
                        $item['former_position'] ?? '',
                    );
                } else {
                    $workplaceAndPosition = $item['workplace_and_position'];
                }

                $employee->relatives()->create([
                    'relationship_type' => $item['relationship_type'],
                    'full_name_cyr' => $item['full_name_cyr'],
                    'birth_year' => $item['birth_year'],
                    'birth_place' => $item['birth_place'],
                    'is_deceased' => $item['is_deceased'] ?? false,
                    'deceased_year' => $item['deceased_year'] ?? null,
                    'workplace_and_position' => $workplaceAndPosition,
                    // Аслий (форматланмаган) лавозим — таҳрирлашда қайта тикланиши учун.
                    'former_position' => ! empty($item['is_deceased']) ? ($item['former_position'] ?? null) : null,
                    'residence_full' => $item['residence_full'],
                ]);
            }
        });
    }
}
