<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Membership is asked about several times over in one entity-scoped request:
 * AuthorizesEntityAccess resolves the entity and then authorises it,
 * the controller asks whether the user leads it, and
 * Slide::scopeVisibleToUser() asks for the whole list. Each used to be its
 * own query against user_entities.
 */
class EntityMembershipMemoizationTest extends TestCase
{
    use RefreshDatabase;

    private function countQueriesOn(string $table, callable $fn): int
    {
        $n = 0;
        DB::listen(function ($q) use (&$n, $table) {
            if (str_contains($q->sql, $table)) {
                $n++;
            }
        });
        $fn();
        return $n;
    }

    public function test_repeated_membership_questions_cost_one_query(): void
    {
        $user = User::factory()->create();
        $a = Entity::create(['name' => 'A']);
        $b = Entity::create(['name' => 'B']);
        $user->entities()->attach($a->id, ['role' => 'admin']);
        $user->entities()->attach($b->id, ['role' => 'viewer']);

        $fresh = User::find($user->id);

        $queries = $this->countQueriesOn('user_entities', function () use ($fresh, $a, $b) {
            $fresh->memberEntityIds();
            $fresh->memberEntityIds();
            $fresh->isEntityAdmin($a->id);
            $fresh->isEntityAdmin($b->id);
            $fresh->entityRole($a->id);
            $fresh->entityRole($b->id);
        });

        $this->assertSame(1, $queries, "Six membership questions should cost one query, took {$queries}");
    }

    public function test_the_answers_are_still_correct(): void
    {
        $user = User::factory()->create();
        $a = Entity::create(['name' => 'A']);
        $b = Entity::create(['name' => 'B']);
        $c = Entity::create(['name' => 'C']);
        $user->entities()->attach($a->id, ['role' => 'admin']);
        $user->entities()->attach($b->id, ['role' => 'viewer']);

        $fresh = User::find($user->id);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $fresh->memberEntityIds());
        $this->assertTrue($fresh->isEntityAdmin($a->id));
        $this->assertFalse($fresh->isEntityAdmin($b->id));
        $this->assertFalse($fresh->isEntityAdmin($c->id));
        $this->assertSame('admin', $fresh->entityRole($a->id));
        $this->assertSame('viewer', $fresh->entityRole($b->id));
        $this->assertNull($fresh->entityRole($c->id));
    }

    public function test_subscribing_in_the_same_request_is_not_served_stale(): void
    {
        $user   = User::factory()->create();
        $entity = Entity::create(['name' => 'Newly Joined']);

        $this->assertSame([], $user->memberEntityIds());   // warms the cache

        $this->actingAs($user)->post("/entities/{$entity->id}/subscribe")->assertRedirect();

        $this->assertContains(
            $entity->id,
            $user->fresh()->memberEntityIds(),
            'A membership added during the request must be visible afterwards.'
        );
    }

    public function test_forgetting_picks_up_a_membership_added_after_the_first_read(): void
    {
        $user   = User::factory()->create();
        $entity = Entity::create(['name' => 'Later']);

        $this->assertSame([], $user->memberEntityIds());

        $user->entities()->attach($entity->id, ['role' => 'admin']);
        $this->assertSame([], $user->memberEntityIds(), 'still cached, as designed');

        $user->forgetEntityRoles();
        $this->assertSame([$entity->id], $user->memberEntityIds());
        $this->assertTrue($user->isEntityAdmin($entity->id));
    }
}
