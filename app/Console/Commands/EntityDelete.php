<?php

namespace App\Console\Commands;

use App\Models\Entity;
use Illuminate\Console\Command;

class EntityDelete extends Command
{
    protected $signature = 'entity:delete
                            {entity : Entity ID or partial name}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Delete an entity (for testing/manual use). Cascades to its shows and user role assignments; slides keep their record but have entity_id cleared.';

    public function handle(): int
    {
        $entity = $this->resolveEntity($this->argument('entity'));

        if (! $entity) {
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Delete entity \"{$entity->name}\" (ID {$entity->id})?")) {
            $this->warn('Aborted.');
            return self::FAILURE;
        }

        $entity->delete();

        $this->info("Entity deleted: [{$entity->id}] {$entity->name}");

        return self::SUCCESS;
    }

    private function resolveEntity(string $search): ?Entity
    {
        // Try numeric ID first
        if (is_numeric($search)) {
            $entity = Entity::find((int) $search);
            if ($entity) {
                return $entity;
            }
            $this->error("No entity found with ID {$search}.");
            return null;
        }

        // Search by name
        $matches = Entity::where('name', 'like', '%' . $search . '%')->get();

        if ($matches->isEmpty()) {
            $this->error("No entity found matching \"{$search}\".");
            return null;
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        // Multiple matches — let user pick
        $this->warn("Multiple entities match \"{$search}\":");
        $choices = $matches->map(fn ($e) => "[{$e->id}] {$e->name}" . ($e->city ? " ({$e->city}, {$e->state})" : ''))->all();

        $choice = $this->choice('Select an entity', $choices);
        $id = (int) ltrim($choice, '[');

        return $matches->firstWhere('id', $id);
    }
}
