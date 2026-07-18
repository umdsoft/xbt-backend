<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Models\HouseZoneState;
use App\Domains\Mahalla\Models\Master\Building;
use App\Domains\Mahalla\Support\MahallaZones;

// Honadon umumiy holati = eng muammoli zona; progress = zonalar o'rtachasi.

/**
 * Kadastr binosidan monitoring HONADONINI (va 4 zona holatini) lazy yaratadi.
 * Worklist manbai — master.buildings; honadon yozuvi birinchi kuzatuvда paydo bo'ladi.
 */
class HouseProvisioner
{
    public function forBuilding(Building $building): House
    {
        $house = House::firstOrCreate(
            ['building_id' => $building->id],
            [
                'district_id' => $building->district_id,
                'mahalla_id' => $building->mahalla_id,
                'street_id' => $building->street_id,
                'cadastral_number' => $building->kadastr,
                'lat' => $building->lat,
                'lng' => $building->lng,
                'address' => $building->address,
                'status' => 'not_started',
                'progress_percent' => 0,
            ],
        );

        $this->ensureZoneStates($house);

        return $house;
    }

    /**
     * Honadonda 4 zona holati mavjudligini kafolatlaydi (yo'qlari yaratiladi).
     */
    public function ensureZoneStates(House $house): void
    {
        $existing = HouseZoneState::where('house_id', $house->id)->pluck('zone')->all();

        foreach (MahallaZones::zoneCodes() as $zone) {
            if (! in_array($zone, $existing, true)) {
                HouseZoneState::create([
                    'house_id' => $house->id,
                    'zone' => $zone,
                    'status' => MahallaZones::DEFAULT_STATUS,
                ]);
            }
        }
    }

    /**
     * Honadon umumiy holatini zona holatlaridan qayta hisoblash (eng muammoli zona)
     * + o'rtacha progress. Zona holati (needs_work/...) -> honadon enum'iga moslanadi.
     */
    public function recomputeHouse(string $houseId): void
    {
        $states = HouseZoneState::where('house_id', $houseId)->get(['status', 'progress_percent']);
        if ($states->isEmpty()) {
            return;
        }

        $overall = MahallaZones::overallStatus($states->pluck('status')->all());
        $avg = (int) round((float) $states->avg('progress_percent'));
        $map = ['needs_work' => 'not_started', 'in_progress' => 'in_progress', 'completed' => 'completed', 'good' => 'completed'];

        House::whereKey($houseId)->update([
            'status' => $map[$overall] ?? 'in_progress',
            'progress_percent' => $avg,
        ]);
    }
}
