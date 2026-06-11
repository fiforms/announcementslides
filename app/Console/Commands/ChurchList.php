<?php

namespace App\Console\Commands;

use App\Models\AdventistEntity;
use Illuminate\Console\Command;

class ChurchList extends Command
{
    protected $signature = 'church:list
                            {--conference= : Filter by conference code}';

    protected $description = 'List all Adventist entities in the database';

    public function handle(): int
    {
        $query = AdventistEntity::orderBy('conference_code')->orderBy('name');

        if ($conference = $this->option('conference')) {
            $query->where('conference_code', strtoupper($conference));
        }

        $rows = $query->get(['org_mast_id', 'entity_type', 'name', 'conference_code']);

        if ($rows->isEmpty()) {
            $this->warn('No entities found.');
            return self::SUCCESS;
        }

        $this->table(
            ['OrgMastID', 'Type', 'Name', 'Conference'],
            $rows->map(fn ($e) => [
                $e->org_mast_id ?? '—',
                $e->entity_type ?? '—',
                $e->name,
                $e->conference_code,
            ])->toArray()
        );

        $this->line("Total: {$rows->count()}");

        return self::SUCCESS;
    }
}
