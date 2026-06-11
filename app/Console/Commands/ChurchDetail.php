<?php

namespace App\Console\Commands;

use App\Models\AdventistEntity;
use Illuminate\Console\Command;

class ChurchDetail extends Command
{
    protected $signature = 'church:detail
                            {--id= : OrgMastID or OMEntityID of the entity}';

    protected $description = 'Show all stored fields for a single Adventist entity';

    public function handle(): int
    {
        $id = $this->option('id') ?? $this->ask('OrgMastID or OMEntityID');

        if (! $id) {
            $this->error('An ID is required.');
            return self::FAILURE;
        }

        $entity = AdventistEntity::where('org_mast_id', $id)
            ->orWhere('om_entity_id', $id)
            ->first();

        if (! $entity) {
            $this->error("No entity found with ID: {$id}");
            return self::FAILURE;
        }

        $fields = $entity->toArray();
        $extra  = $fields['extra'] ?? null;
        unset($fields['extra'], $fields['id'], $fields['created_at'], $fields['updated_at']);

        $this->table(
            ['Field', 'Value'],
            collect($fields)->map(fn ($v, $k) => [
                $k,
                is_null($v) ? '<fg=gray>null</>' : $v,
            ])->values()->toArray()
        );

        if (! empty($extra)) {
            $this->newLine();
            $this->line('<fg=yellow>Extra fields:</>');
            $this->table(
                ['Field', 'Value'],
                collect($extra)->map(fn ($v, $k) => [$k, $v])->values()->toArray()
            );
        }

        return self::SUCCESS;
    }
}
