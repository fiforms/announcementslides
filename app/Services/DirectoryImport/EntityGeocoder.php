<?php

namespace App\Services\DirectoryImport;

use App\Models\Entity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fills in missing latitude/longitude on entities using the free U.S. Census
 * Geocoder batch endpoint (no API key). Only entities that have a street
 * address and no coordinates are looked up; existing coordinates are never
 * touched.
 *
 * Only street addresses go out, never any user data. The Census service is
 * U.S.-only, so non-US or unmatched addresses are reported, not guessed.
 */
class EntityGeocoder
{
    public const ENDPOINT = 'https://geocoding.geo.census.gov/geocoder/locations/addressbatch';

    private const BATCH_SIZE = 500;

    /** Active entities with an address (street + city or zip) and no coordinates. */
    public function eligible(): Builder
    {
        return Entity::query()
            ->where('deactivated', false)
            ->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))
            ->whereNotNull('address')->where('address', '!=', '')
            ->where(fn ($q) => $q->whereNotNull('zip')->where('zip', '!=', '')
                ->orWhere(fn ($q) => $q->whereNotNull('city')->where('city', '!=', '')));
    }

    /**
     * @param  bool  $dryRun  look up and report, but do not save
     * @return array{total: int, matched: int, unmatched: int, unmatched_names: list<string>}
     *
     * @throws RuntimeException if the Census service cannot be reached or errors
     */
    public function geocode(?int $limit = null, bool $dryRun = false, ?callable $tick = null): array
    {
        $query = $this->eligible()->orderBy('id');
        if ($limit) {
            $query->limit($limit);
        }
        $entities = $query->get();

        $result = ['total' => $entities->count(), 'matched' => 0, 'unmatched' => 0, 'unmatched_names' => []];

        foreach ($entities->chunk(self::BATCH_SIZE) as $chunk) {
            $coords = $this->lookup($chunk->all());

            foreach ($chunk as $entity) {
                if (isset($coords[$entity->id])) {
                    $result['matched']++;
                    if (! $dryRun) {
                        $entity->update($coords[$entity->id]);
                    }
                } else {
                    $result['unmatched']++;
                    $result['unmatched_names'][] = $entity->name;
                }

                if ($tick) {
                    $tick();
                }
            }
        }

        return $result;
    }

    /**
     * @param  list<Entity>  $entities
     * @return array<int, array{latitude: float, longitude: float}> keyed by entity id; only matches
     */
    private function lookup(array $entities): array
    {
        $csv = fopen('php://temp', 'r+');
        foreach ($entities as $e) {
            fputcsv($csv, [$e->id, $e->address, $e->city, $e->state, $e->zip]);
        }
        rewind($csv);
        $body = stream_get_contents($csv);
        fclose($csv);

        $response = Http::timeout(180)
            ->attach('addressFile', $body, 'addresses.csv')
            ->post(self::ENDPOINT, ['benchmark' => 'Public_AR_Current']);

        if (! $response->successful()) {
            throw new RuntimeException("The Census geocoder returned HTTP {$response->status()}.");
        }

        $out = [];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $response->body());
        rewind($stream);

        // Columns: id, input address, Match|No_Match|Tie, Exact|Non_Exact, matched address, "lon,lat", ...
        while (($row = fgetcsv($stream)) !== false) {
            if (($row[2] ?? '') !== 'Match' || ! isset($row[5])) {
                continue;
            }

            $parts = explode(',', $row[5]);

            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                $out[(int) $row[0]] = ['longitude' => (float) $parts[0], 'latitude' => (float) $parts[1]];
            }
        }
        fclose($stream);

        return $out;
    }
}
