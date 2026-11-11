<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEntityDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_details_include_entity_fields_and_users_leaders_first(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $entity = Entity::create(['name' => 'Aiken SDA Church', 'org_mast_id' => 'ANT8B6', 'city' => 'Aiken']);
        $viewer = User::factory()->create(['name' => 'Aaron Viewer']);
        $leader = User::factory()->create(['name' => 'Zed Leader']);
        $entity->users()->attach($viewer->id, ['role' => 'viewer']);
        $entity->users()->attach($leader->id, ['role' => 'admin']);

        $this->actingAs($admin)->getJson(route('admin.entities.show', $entity))
            ->assertOk()
            ->assertJsonPath('org_mast_id', 'ANT8B6')
            ->assertJsonCount(2, 'users')
            ->assertJsonPath('users.0.name', 'Zed Leader')
            ->assertJsonPath('users.0.entity_role', 'admin')
            ->assertJsonPath('users.1.entity_role', 'viewer');
    }

    public function test_index_lists_all_entities_with_counts_and_is_admin_only(): void
    {
        $entity = Entity::create(['name' => 'Camden SDA Church', 'deactivated' => true]);
        $entity->users()->attach(User::factory()->create()->id, ['role' => 'admin']);

        $user = User::factory()->create(['role' => 'contributor']);
        $this->actingAs($user)->getJson(route('admin.entities.show', $entity))->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->withoutVite()->actingAs($admin)->get(route('admin.entities.index'))
            ->assertInertia(fn ($p) => $p->has('entities', 1)
                ->where('entities.0.deactivated', true)
                ->where('entities.0.leaders_count', 1)
                ->where('entities.0.members_count', 1));
    }
}
