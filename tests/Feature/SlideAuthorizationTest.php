<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Show;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterisation tests for slide authorisation. Written against the
 * hand-copied abort_unless() checks these controllers used to carry, and
 * they must keep passing verbatim through the move to SlidePolicy — that is
 * the whole point of them. Every case here was verified against the
 * pre-refactor code first.
 *
 * Note the 403/404 split: a caller who may not act on a slide gets 403, but
 * a slide belonging to a *different* entity gets 404, so the entity-scoped
 * screens don't confirm that someone else's slide exists.
 */
class SlideAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Entity $entity;
    private Entity $otherEntity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entity      = Entity::create(['name' => 'Alpha Church']);
        $this->otherEntity = Entity::create(['name' => 'Beta Church']);
        Show::mainFor($this->entity);
    }

    private function user(string $role, ?Entity $entity = null, string $entityRole = 'admin'): User
    {
        $u = User::factory()->create(['role' => $role]);
        if ($entity) {
            $u->entities()->attach($entity->id, ['role' => $entityRole]);
        }
        return $u;
    }

    private function slide(?Entity $entity, User $owner): Slide
    {
        return Slide::create([
            'title' => 'S', 'status' => 'published',
            'uploaded_by' => $owner->id, 'entity_id' => $entity?->id,
        ]);
    }

    private function editEntitySlide(User $as, Entity $entity, Slide $slide)
    {
        return $this->actingAs($as)->get("/entity/{$entity->id}/slides/{$slide->id}/edit");
    }

    // ── entity-scoped screens ────────────────────────────────────────────

    public function test_site_admin_may_edit_any_entity_slide_even_when_not_the_owner(): void
    {
        $owner = $this->user('contributor', $this->entity);
        $slide = $this->slide($this->entity, $owner);

        $this->editEntitySlide($this->user('admin'), $this->entity, $slide)->assertOk();
    }

    public function test_entity_admin_may_edit_their_own_slide(): void
    {
        $leader = $this->user('contributor', $this->entity);
        $slide  = $this->slide($this->entity, $leader);

        $this->editEntitySlide($leader, $this->entity, $slide)->assertOk();
    }

    public function test_entity_admin_may_not_edit_a_slide_someone_else_uploaded(): void
    {
        $leader = $this->user('contributor', $this->entity);
        $other  = $this->user('contributor', $this->entity);
        $slide  = $this->slide($this->entity, $other);

        $this->editEntitySlide($leader, $this->entity, $slide)->assertForbidden();
    }

    public function test_a_plain_entity_member_may_not_edit_entity_slides(): void
    {
        $member = $this->user('contributor', $this->entity, entityRole: 'viewer');
        $slide  = $this->slide($this->entity, $member);

        $this->editEntitySlide($member, $this->entity, $slide)->assertForbidden();
    }

    public function test_a_non_member_may_not_edit_entity_slides(): void
    {
        $outsider = $this->user('contributor', $this->otherEntity);
        $owner    = $this->user('contributor', $this->entity);
        $slide    = $this->slide($this->entity, $owner);

        $this->editEntitySlide($outsider, $this->entity, $slide)->assertForbidden();
    }

    /** Mismatched entity is 404, not 403 — it must not confirm the slide exists. */
    public function test_a_slide_from_another_entity_is_not_found_rather_than_forbidden(): void
    {
        $leader  = $this->user('admin', $this->entity);
        $foreign = $this->slide($this->otherEntity, $leader);

        $this->editEntitySlide($leader, $this->entity, $foreign)->assertNotFound();
    }

    public function test_a_global_slide_is_not_found_on_an_entity_screen(): void
    {
        $leader = $this->user('admin', $this->entity);
        $global = $this->slide(null, $leader);

        $this->editEntitySlide($leader, $this->entity, $global)->assertNotFound();
    }

    // ── my-slides (unscoped, own uploads) ────────────────────────────────

    public function test_a_contributor_may_edit_their_own_global_slide(): void
    {
        $owner = $this->user('contributor');

        $this->actingAs($owner)->get("/my-slides/{$this->slide(null, $owner)->id}/edit")->assertOk();
    }

    /** A user demoted to viewer keeps old uploads but loses the right to edit them. */
    public function test_a_demoted_viewer_may_not_edit_a_slide_they_uploaded(): void
    {
        $demoted = $this->user('viewer');

        $this->actingAs($demoted)->get("/my-slides/{$this->slide(null, $demoted)->id}/edit")->assertForbidden();
    }

    public function test_my_slides_does_not_reach_an_entity_scoped_slide(): void
    {
        $owner = $this->user('contributor', $this->entity);

        $this->actingAs($owner)
            ->get("/my-slides/{$this->slide($this->entity, $owner)->id}/edit")
            ->assertForbidden();
    }

    public function test_a_contributor_may_not_edit_someone_elses_global_slide(): void
    {
        $owner  = $this->user('contributor');
        $other  = $this->user('contributor');

        $this->actingAs($other)->get("/my-slides/{$this->slide(null, $owner)->id}/edit")->assertForbidden();
    }
}
