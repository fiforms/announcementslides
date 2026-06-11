<?php

namespace App\Console\Commands;

use App\Models\AdventistEntity;
use App\Models\Entity;
use Illuminate\Console\Command;

class EntitySync extends Command
{
    protected $signature = 'entity:sync
                            {--conference= : Only sync entities from this conference code}';

    protected $description = 'Sync the entities table from the adventist_entities table';

    public function handle(): int
    {
        $query = AdventistEntity::query()
            ->whereNotNull('org_mast_id')
            ->when($this->option('conference'), fn ($q, $c) => $q->where('conference_code', $c));

        $total = $query->count();

        if ($total === 0) {
            $this->warn('No adventist_entities records found matching the given criteria.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $created = 0;
        $updated = 0;
        $reactivated = 0;
        $seenOrgMastIds = [];

        $query->chunk(200, function ($adventistEntities) use ($bar, &$created, &$updated, &$reactivated, &$seenOrgMastIds) {
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
                    'website'     => $ae->website,
                    'is_custom'   => false,
                    'deactivated' => false,
                ];

                $existing = Entity::where('org_mast_id', $ae->org_mast_id)->first();

                if ($existing) {
                    if ($existing->deactivated) {
                        $reactivated++;
                    }
                    $existing->update($data);
                    $updated++;
                } else {
                    Entity::create(array_merge($data, ['org_mast_id' => $ae->org_mast_id]));
                    $created++;
                }

                $bar->advance();
            }
        });

        // Deactivate non-custom entities whose org_mast_id was not in this sync's source.
        // When --conference is set we only touch entities that belong to that conference's
        // known org_mast_id space, so we scope to ids previously imported from this run's
        // source set (adventist_entities filtered by conference) plus any already present.
        $deactivatedQuery = Entity::where('is_custom', false)
            ->where('deactivated', false)
            ->whereNotNull('org_mast_id');

        if ($this->option('conference')) {
            // Only deactivate entities whose org_mast_id was previously sourced from this
            // conference. We detect this by checking which non-custom entities with those
            // org_mast_ids exist but weren't seen in the current source.
            $conferenceOrgMastIds = AdventistEntity::where('conference_code', $this->option('conference'))
                ->pluck('org_mast_id');
            $deactivatedQuery->whereIn('org_mast_id', $conferenceOrgMastIds);
        }

        $deactivated = $deactivatedQuery
            ->whereNotIn('org_mast_id', $seenOrgMastIds)
            ->update(['deactivated' => true]);

        $bar->finish();
        $this->newLine();
        $this->info("Done. Created: {$created}  Updated: {$updated}  Reactivated: {$reactivated}  Deactivated: {$deactivated}");

        return self::SUCCESS;
    }
}
