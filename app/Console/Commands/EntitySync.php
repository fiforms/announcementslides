<?php

namespace App\Console\Commands;

use App\Services\DirectoryImport\EntitySyncer;
use Illuminate\Console\Command;

class EntitySync extends Command
{
    protected $signature = 'entity:sync
                            {--conference= : Only sync entities from this conference code}';

    protected $description = 'Sync the entities table from the adventist_entities table';

    public function handle(EntitySyncer $syncer): int
    {
        $result = $syncer->sync($this->option('conference'));

        if ($result['total'] === 0) {
            $this->warn('No adventist_entities records found matching the given criteria.');
            return self::SUCCESS;
        }

        $this->info("Done. Created: {$result['created']}  Updated: {$result['updated']}  Reactivated: {$result['reactivated']}  Deactivated: {$result['deactivated']}");

        return self::SUCCESS;
    }
}
