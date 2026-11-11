<?php

namespace App\Services\DirectoryImport;

use App\Models\AdventistEntity;
use App\Models\Entity;

/**
 * Distills the raw `adventist_entities` import table into the working
 * `entities` table. Shared by `entity:sync` and the admin import page.
 */
class EntitySyncer
{
    /**
     * @param  callable|null  $tick  called once per processed source row
     * @return array{total:int, created:int, updated:int, reactivated:int, deactivated:int}
     */
    public function sync(?string $conference = null, ?callable $tick = null): array
    {
        $query = AdventistEntity::query()
            ->whereNotNull('org_mast_id')
            ->when($conference, fn ($q, $c) => $q->where('conference_code', $c));

        $result = ['total' => $query->count(), 'created' => 0, 'updated' => 0, 'reactivated' => 0, 'deactivated' => 0];

        if ($result['total'] === 0) {
            return $result;
        }

        $seenOrgMastIds = [];

        $query->chunk(200, function ($adventistEntities) use ($tick, &$result, &$seenOrgMastIds) {
            foreach ($adventistEntities as $ae) {
                $seenOrgMastIds[] = $ae->org_mast_id;

                $data = [
                    'name'        => $ae->name,
                    'entity_type' => $ae->entity_type,
                    'address'     => $ae->address,
                    'city'        => $ae->city,
                    'state'       => $ae->state,
                    'zip'         => $ae->zip,
                    'country'     => $ae->country,
                    'latitude'    => $ae->latitude,
                    'longitude'   => $ae->longitude,
                    'website'     => $ae->website,
                    'is_custom'   => false,
                    'deactivated' => false,
                ];

                $existing = Entity::where('org_mast_id', $ae->org_mast_id)->first();

                // A source row without coordinates (e.g. from data.json) must not
                // wipe ones that were geocoded or came from an earlier scrape.
                if ($existing && ($ae->latitude === null || $ae->longitude === null)) {
                    unset($data['latitude'], $data['longitude']);
                }

                if ($existing) {
                    if ($existing->deactivated) {
                        $result['reactivated']++;
                    }
                    $existing->update($data);
                    $result['updated']++;
                } else {
                    Entity::create(array_merge($data, ['org_mast_id' => $ae->org_mast_id]));
                    $result['created']++;
                }

                if ($tick) {
                    $tick();
                }
            }
        });

        // Deactivate non-custom entities that were not in this sync's source.
        // With a conference filter, only entities sourced from that conference
        // are candidates.
        $deactivate = Entity::where('is_custom', false)
            ->where('deactivated', false)
            ->whereNotNull('org_mast_id');

        if ($conference) {
            $deactivate->whereIn(
                'org_mast_id',
                AdventistEntity::where('conference_code', $conference)->pluck('org_mast_id')
            );
        }

        $result['deactivated'] = $deactivate
            ->whereNotIn('org_mast_id', $seenOrgMastIds)
            ->update(['deactivated' => true]);

        return $result;
    }
}
