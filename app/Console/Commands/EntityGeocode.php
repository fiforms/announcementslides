<?php

namespace App\Console\Commands;

use App\Services\DirectoryImport\EntityGeocoder;
use Illuminate\Console\Command;
use RuntimeException;

class EntityGeocode extends Command
{
    protected $signature = 'entity:geocode
                            {--limit= : Only look up this many entities}
                            {--dry-run : Look up and report, but do not save coordinates}';

    protected $description = 'Fill in missing entity latitude/longitude using the free U.S. Census geocoder';

    public function handle(EntityGeocoder $geocoder): int
    {
        $count = $geocoder->eligible()->count();

        if ($count === 0) {
            $this->info('Nothing to do: every active entity with an address already has coordinates.');
            return self::SUCCESS;
        }

        $this->info("Looking up {$count} entities with the U.S. Census geocoder...");

        try {
            $result = $geocoder->geocode(
                $this->option('limit') ? (int) $this->option('limit') : null,
                (bool) $this->option('dry-run'),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $verb = $this->option('dry-run') ? 'Would update' : 'Updated';
        $this->info("{$verb} {$result['matched']} of {$result['total']}. No match: {$result['unmatched']}.");

        foreach ($result['unmatched_names'] as $name) {
            $this->line("  - {$name}");
        }

        return self::SUCCESS;
    }
}
