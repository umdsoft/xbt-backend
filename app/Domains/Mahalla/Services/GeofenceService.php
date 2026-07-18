<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

/**
 * Geofence: yuklangan rasm koordinatasi honadon koordinatasiga yaqinmi?
 * Haversine formula bilan metrdagi masofa.
 */
class GeofenceService
{
    private const EARTH_RADIUS_M = 6_371_000.0;

    public function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function isWithin(float $distanceM, ?int $radiusM = null): bool
    {
        $radiusM ??= (int) config('mahalla.geofence_radius_m', 75);

        return $distanceM <= $radiusM;
    }
}
