<?php

namespace App\Console\Commands;

use App\Models\Entity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class EntityCreate extends Command
{
    protected $signature = 'entity:create
                            {--name= : Entity name}
                            {--type= : Entity type (e.g. church, school)}
                            {--city=}
                            {--state=}';

    protected $description = 'Create a custom entity (for testing/manual use, not imported from the church directory)';

    public function handle(): int
    {
        $name = $this->option('name') ?? $this->ask('Name');

        $validator = Validator::make(compact('name'), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $entity = Entity::create([
            'name'        => $name,
            'entity_type' => $this->option('type'),
            'city'        => $this->option('city'),
            'state'       => $this->option('state'),
            'is_custom'   => true,
            'deactivated' => false,
        ]);

        $this->info("Entity created: [{$entity->id}] {$entity->name}");

        return self::SUCCESS;
    }
}
