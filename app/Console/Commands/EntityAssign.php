<?php

namespace App\Console\Commands;

use App\Models\Entity;
use App\Models\User;
use Illuminate\Console\Command;

class EntityAssign extends Command
{
    protected $signature = 'entity:assign
                            {email : The user\'s email address}
                            {entity : Entity ID or partial name}
                            {--role=admin : Role to assign (admin or viewer)}
                            {--remove : Remove this user\'s role from the entity instead}';

    protected $description = 'Assign (or remove) a user role for an entity';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user found with email: {$this->argument('email')}");
            return self::FAILURE;
        }

        $entity = $this->resolveEntity($this->argument('entity'));

        if (! $entity) {
            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $user->entities()->detach($entity->id);
            $this->info("Removed {$user->email} from entity \"{$entity->name}\".");
            return self::SUCCESS;
        }

        $role = $this->option('role');

        if (! in_array($role, ['admin', 'viewer'])) {
            $this->error("Invalid role \"{$role}\". Must be admin or viewer.");
            return self::FAILURE;
        }

        $user->entities()->syncWithoutDetaching([
            $entity->id => ['role' => $role, 'granted_by' => null],
        ]);

        // If the role changed, update it explicitly
        $user->entities()->updateExistingPivot($entity->id, ['role' => $role]);

        $this->info("Assigned {$user->email} as {$role} for \"{$entity->name}\".");

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
