<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * `/` is public and `entity_id` is just a query parameter. An arbitrary one
 * used to resolve an Entity and publish that entity's show list into the
 * page props — telling an anonymous caller both that the entity exists (a
 * real id rendered, an unknown one 404'd) and what its leaders had named
 * their shows.
 */
class EntityIdMetadataTest extends TestCase
{
    use RefreshDatabase;

    private Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entity = Entity::create(['name' => 'Alpha Church']);
        Show::mainFor($this->entity);
        Show::create([
            'entity_id' => $this->entity->id,
            'name'      => 'Board Meeting Rota',
            'is_main'   => false,
        ]);
    }

    public function test_a_guest_does_not_receive_another_entitys_show_names(): void
    {
        $response = $this->get('/?entity_id='.$this->entity->id)->assertOk();

        // The name a leader gave a private show is the thing that must not
        // appear anywhere in the payload.
        $response->assertDontSee('Board Meeting Rota', escape: false);
        $response->assertInertia(fn (Assert $p) => $p->where('entityId', null));
    }

    public function test_a_guest_cannot_probe_whether_an_entity_exists(): void
    {
        // Both a real and an invented id must respond identically — a 404 on
        // one and a 200 on the other is the enumeration oracle.
        $real    = $this->get('/?entity_id='.$this->entity->id);
        $invented = $this->get('/?entity_id=999999');

        $real->assertOk();
        $invented->assertOk();
        $this->assertSame($real->status(), $invented->status());
    }

    public function test_a_logged_in_non_member_is_treated_the_same(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get('/?entity_id='.$this->entity->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('entityId', null));
    }

    public function test_a_member_still_gets_their_own_entitys_board_and_shows(): void
    {
        $member = User::factory()->create();
        $member->entities()->attach($this->entity->id, ['role' => 'admin']);

        $this->actingAs($member)
            ->get('/?entity_id='.$this->entity->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('entityId', $this->entity->id)
                ->count('availableShows', 2));
    }

    public function test_the_archive_resolves_entity_id_the_same_way(): void
    {
        $member = User::factory()->create();
        $member->entities()->attach($this->entity->id, ['role' => 'admin']);

        $this->get('/archive?entity_id='.$this->entity->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('entityId', null)->where('isAdmin', false));

        $this->actingAs($member)
            ->get('/archive?entity_id='.$this->entity->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('entityId', $this->entity->id)->where('isAdmin', true));
    }
}
