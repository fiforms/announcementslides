<?php

namespace App\Support;

use App\Models\Entity;

class NearbyEntities
{
    /**
     * Return the IDs of OTHER entities within $radiusMiles of the home entity,
     * "as the crow flies". Uses a cheap bounding-box pre-filter in SQL, then
     * refines to an exact great-circle radius (haversine) in PHP so it stays
     * portable across database engines.
     *
     * @return array<int>
     */
    public static function within(Entity $home, float $radiusMiles): array
    {
        if ($home->latitude === null || $home->longitude === null || $radiusMiles <= 0) {
            return [];
        }

        $lat = (float) $home->latitude;
        $lng = (float) $home->longitude;

        // Bounding box in degrees. ~69 miles per degree of latitude; longitude
        // degrees shrink toward the poles, so divide by cos(latitude).
        $latDelta = $radiusMiles / 69.0;
        $lngDelta = $radiusMiles / (69.0 * max(cos(deg2rad($lat)), 0.01));

        $candidates = Entity::query()
            ->where('id', '!=', $home->id)
            ->where('deactivated', false)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta])
            ->get(['id', 'latitude', 'longitude']);

        return $candidates
            ->filter(fn ($e) => self::haversineMiles($lat, $lng, (float) $e->latitude, (float) $e->longitude) <= $radiusMiles)
            ->pluck('id')
            ->all();
    }

    /**
     * Great-circle distance between two points, in miles.
     */
    public static function haversineMiles(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 3958.8; // miles

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadius * 2 * asin(min(1.0, sqrt($a)));
    }
}
