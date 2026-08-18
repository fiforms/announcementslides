<?php

namespace App\Console\Commands;

use App\Models\AdventistEntity;
use Illuminate\Console\Command;

class ChurchExport extends Command
{
    protected $signature = 'church:export
                            {path : Path to write the CSV file to}
                            {--conference= : Only export a specific conference code (e.g. CRLC)}';

    protected $description = 'Export loaded Adventist entities to a CSV file for backup';

    private const COLUMNS = [
        'conference_code', 'om_entity_id', 'org_mast_id', 'entity_type', 'name',
        'address', 'city', 'state', 'zip', 'country', 'latitude', 'longitude',
        'phone', 'website', 'pastor', 'language', 'facebook', 'instagram',
        'twitter', 'youtube', 'extra',
    ];

    public function handle(): int
    {
        $query = AdventistEntity::query();

        if ($conference = $this->option('conference')) {
            $query->where('conference_code', strtoupper(trim($conference)));
        }

        $entities = $query->orderBy('conference_code')->orderBy('om_entity_id')->get();

        if ($entities->isEmpty()) {
            $this->warn('No entities found to export.');
            return self::FAILURE;
        }

        $path   = $this->argument('path');
        $handle = fopen($path, 'w');

        if ($handle === false) {
            $this->error("Could not open {$path} for writing.");
            return self::FAILURE;
        }

        fputcsv($handle, self::COLUMNS);

        foreach ($entities as $entity) {
            fputcsv($handle, collect(self::COLUMNS)->map(function ($column) use ($entity) {
                $value = $entity->{$column};
                return $column === 'extra' ? json_encode($value) : $value;
            })->toArray());
        }

        fclose($handle);

        $this->info("Exported {$entities->count()} entities to {$path}.");

        return self::SUCCESS;
    }
}
