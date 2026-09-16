<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AuthorizesEntityAccess remembers the entity a user is working in so a bare
 * bookmark still resolves. It used to write that to the session *before*
 * checking membership, so one rejected ?entity_id= stuck — and every later
 * visit without a query string read the poisoned value back out and 403'd on
 * it, instead of falling back to the user's own entity.
 *
 * Never an access bypass: the value is re-validated on read, so a poisoned
 * session was refused too. The damage was to the legitimate user.
 */
class EntitySessionPoisoningTest extends TestCase
{
    use RefreshDatabase;

    private function leaderOf(Entity $entity): User
    {
        $u = User::factory()->create(['role' => 'contributor']);
        $u->entities()->attach($entity->id, ['role' => 'admin']);
        return $u;
    }

    public function test_a_rejected_entity_id_does_not_break_later_visits(): void
    {
        $mine      = Entity::create(['name' => 'Mine']);
        $notMine   = Entity::create(['name' => 'Not Mine']);
        Show::mainFor($mine);
        $leader = $this->leaderOf($mine);

        // A poke at someone else's entity is refused, as it always was.
        $this->actingAs($leader)->get("/local-slides?entity_id={$notMine->id}")->assertForbidden();

        // The bare URL must still resolve to the user's own entity. The trait
        // canonicalises a bare index onto ?entity_id=, so the redirect target
        // is what proves which entity it landed on.
        $this->actingAs($leader)->get('/local-slides')
            ->assertRedirect(route('local-slides.index', ['entity_id' => $mine->id]));
    }

    public function test_an_authorised_entity_id_is_still_remembered(): void
    {
        $mine = Entity::create(['name' => 'Mine']);
        $also = Entity::create(['name' => 'Also Mine']);
        Show::mainFor($mine);
        Show::mainFor($also);

        $leader = $this->leaderOf($mine);
        $leader->entities()->attach($also->id, ['role' => 'admin']);

        // With two entities there is no sole-entity fallback, so a later bare
        // visit can only work if the first one was remembered.
        $this->actingAs($leader)->get("/local-slides?entity_id={$also->id}")->assertOk();

        $this->actingAs($leader)->get('/local-slides')
            ->assertRedirect(route('local-slides.index', ['entity_id' => $also->id]))
            ->assertSessionHas('current_entity_id', $also->id);
    }

    public function test_the_session_is_never_given_an_unauthorised_value(): void
    {
        $mine    = Entity::create(['name' => 'Mine']);
        $notMine = Entity::create(['name' => 'Not Mine']);
        Show::mainFor($mine);
        $leader = $this->leaderOf($mine);

        $this->actingAs($leader)
            ->get("/local-slides?entity_id={$notMine->id}")
            ->assertForbidden()
            ->assertSessionMissing('current_entity_id');
    }
}
