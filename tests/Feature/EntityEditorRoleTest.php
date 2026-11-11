<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\EntityInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityEditorRoleTest extends TestCase
{
    use RefreshDatabase;

    private Entity $entity;
    private User $leader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entity = Entity::create(['name' => 'Test Church']);
        $this->leader = $this->member('admin');
    }

    private function member(string $role, ?Entity $entity = null, string $global = 'viewer'): User
    {
        $u = User::factory()->create(['role' => $global]);
        $u->entities()->attach(($entity ?? $this->entity)->id, ['role' => $role]);

        return $u;
    }

    private function role(User $u, ?Entity $entity = null): ?string
    {
        return $u->entities()->where('entities.id', ($entity ?? $this->entity)->id)->first()?->pivot->role;
    }

    public function test_editor_has_the_same_manage_access_as_a_leader(): void
    {
        $editor = $this->member('editor');

        $this->assertTrue($editor->isEntityAdmin($this->entity->id));
        $this->assertFalse($editor->isEntityLeader($this->entity->id));
        $this->assertContains($this->entity->id, $editor->leaderEntityIds());
        $this->assertContains($this->entity->id, $editor->viewableEntityIds());

        $this->actingAs($editor)->get(route('entity.slides.index', $this->entity))->assertOk();
        $this->actingAs($editor)->get(route('play-links.index', ['entity_id' => $this->entity->id]))->assertOk();
        $this->actingAs($editor)->get(route('local-slides.index', ['entity_id' => $this->entity->id]))->assertOk();
    }

    public function test_follower_still_cannot_manage(): void
    {
        $viewer = $this->member('viewer');

        $this->actingAs($viewer)->get(route('entity.slides.index', $this->entity))->assertForbidden();
    }

    public function test_leader_sees_everyone_on_the_team_page(): void
    {
        $editor = $this->member('editor');
        $viewer = $this->member('viewer');

        $this->actingAs($this->leader)->get(route('entity.people.index', $this->entity))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Entity/People')
                ->has('people', 3)
                ->where('people.0.role', 'admin')
                ->where('people.1.role', 'editor')
                ->where('people.2.role', 'viewer'));
    }

    public function test_editors_and_followers_cannot_open_or_use_the_team_page(): void
    {
        $editor = $this->member('editor');
        $viewer = $this->member('viewer');
        $target = $this->member('editor');

        foreach ([$editor, $viewer] as $actor) {
            $this->actingAs($actor)->get(route('entity.people.index', $this->entity))->assertForbidden();
            $this->actingAs($actor)->post(route('entity.people.store', $this->entity), ['email' => 'x@example.com'])->assertForbidden();
            $this->actingAs($actor)->delete(route('entity.people.destroy', [$this->entity, $target]))->assertForbidden();
        }
        $this->assertSame('editor', $this->role($target));
    }

    public function test_leader_of_another_entity_has_no_power_here(): void
    {
        $other = Entity::create(['name' => 'Other Church']);
        $stranger = $this->member('admin', $other);

        $this->actingAs($stranger)->get(route('entity.people.index', $this->entity))->assertForbidden();
    }

    public function test_leader_makes_an_existing_follower_an_editor(): void
    {
        $viewer = $this->member('viewer');

        $this->actingAs($this->leader)
            ->post(route('entity.people.store', $this->entity), ['email' => strtoupper($viewer->email)])
            ->assertRedirect();

        $this->assertSame('editor', $this->role($viewer));
    }

    public function test_leader_adds_an_existing_user_who_has_no_relationship(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->leader)->post(route('entity.people.store', $this->entity), ['email' => $user->email]);

        $this->assertSame('editor', $this->role($user));
        $this->assertSame('viewer', $user->fresh()->role, 'Global role must not change.');
    }

    public function test_adding_never_demotes_another_leader(): void
    {
        $coLeader = $this->member('admin');

        $this->actingAs($this->leader)->post(route('entity.people.store', $this->entity), ['email' => $coLeader->email]);

        $this->assertSame('admin', $this->role($coLeader));
    }

    public function test_leader_invites_an_email_with_no_account_and_it_applies_on_first_sign_in(): void
    {
        $this->actingAs($this->leader)
            ->post(route('entity.people.store', $this->entity), ['email' => 'New.Person@Example.com'])
            ->assertRedirect();

        $this->assertDatabaseHas('entity_invitations', ['email' => 'new.person@example.com', 'entity_id' => $this->entity->id]);

        $user = User::factory()->create(['email' => 'new.person@example.com', 'role' => 'viewer']);
        EntityInvitation::redeemFor($user);

        $this->assertSame('editor', $this->role($user));
        $this->assertSame('viewer', $user->role);
        $this->assertDatabaseCount('entity_invitations', 0);
    }

    public function test_leader_revokes_an_editor(): void
    {
        $editor = $this->member('editor');

        $this->actingAs($this->leader)->delete(route('entity.people.destroy', [$this->entity, $editor]))->assertRedirect();

        $this->assertNull($this->role($editor));
    }

    public function test_leader_cannot_remove_a_leader_or_a_follower_or_themselves(): void
    {
        $coLeader = $this->member('admin');
        $viewer = $this->member('viewer');

        $this->actingAs($this->leader)->delete(route('entity.people.destroy', [$this->entity, $coLeader]))->assertForbidden();
        $this->actingAs($this->leader)->delete(route('entity.people.destroy', [$this->entity, $viewer]))->assertForbidden();
        $this->actingAs($this->leader)->delete(route('entity.people.destroy', [$this->entity, $this->leader]))->assertForbidden();

        $this->assertSame('admin', $this->role($coLeader));
        $this->assertSame('viewer', $this->role($viewer));
        $this->assertSame('admin', $this->role($this->leader));
    }

    public function test_leader_cancels_an_invitation_only_for_their_own_entity(): void
    {
        $other = Entity::create(['name' => 'Other Church']);
        $mine = EntityInvitation::create(['email' => 'a@example.com', 'entity_id' => $this->entity->id, 'role' => 'editor']);
        $theirs = EntityInvitation::create(['email' => 'b@example.com', 'entity_id' => $other->id, 'role' => 'editor']);

        $this->actingAs($this->leader)->delete(route('entity.people.invitations.destroy', [$this->entity, $theirs]))->assertNotFound();
        $this->actingAs($this->leader)->delete(route('entity.people.invitations.destroy', [$this->entity, $mine]))->assertRedirect();

        $this->assertDatabaseMissing('entity_invitations', ['id' => $mine->id]);
        $this->assertDatabaseHas('entity_invitations', ['id' => $theirs->id]);
    }

    public function test_site_admin_can_still_set_every_entity_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();

        foreach (['editor', 'admin', 'viewer'] as $role) {
            $this->actingAs($admin)->post(route('admin.users.entities.attach', $user), ['entity_id' => $this->entity->id, 'role' => $role]);
            $this->actingAs($admin)->patch(route('admin.users.entities.update', [$user, $this->entity]), ['role' => $role])->assertRedirect();
            $this->assertSame($role, $this->role($user));
        }
    }

    public function test_site_admin_can_use_the_team_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('entity.people.index', $this->entity))->assertOk();
    }
}
