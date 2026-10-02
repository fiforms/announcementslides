<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Show;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImmutableSlideTest extends TestCase
{
    use RefreshDatabase;

    private function setUpLeader(): array
    {
        $entity = Entity::create(['name' => 'Test Church']);
        $leader = User::factory()->create();
        $leader->entities()->attach($entity->id, ['role' => 'admin']);

        return [$entity, $leader, Show::mainFor($entity)];
    }

    private function globalSlide(array $attrs = []): Slide
    {
        return Slide::create(array_merge(['title' => 'G', 'status' => 'published', 'immutable' => true, 'uploaded_by' => User::factory()->create()->id], $attrs));
    }

    private function remove($leader, Show $show, Slide $slide, Entity $entity)
    {
        return $this->actingAs($leader)->delete(route('shows.slides.detach', [
            'show' => $show->id, 'slide' => $slide->id, 'entity_id' => $entity->id,
        ]));
    }

    public function test_leader_cannot_remove_live_immutable_slide(): void
    {
        [$entity, $leader, $show] = $this->setUpLeader();
        $slide = $this->globalSlide();
        $show->slides()->attach($slide->id, ['sort_order' => 1, 'auto_added' => true]);

        $this->remove($leader, $show, $slide, $entity)->assertStatus(422);
        $this->assertTrue($show->slides()->where('slides.id', $slide->id)->exists());
    }

    public function test_immutable_slide_can_be_removed_from_non_main_show(): void
    {
        [$entity, $leader] = $this->setUpLeader();
        $extra = Show::create(['entity_id' => $entity->id, 'name' => 'Extra', 'is_main' => false]);
        $slide = $this->globalSlide();
        $extra->slides()->attach($slide->id, ['sort_order' => 1]);

        $this->remove($leader, $extra, $slide, $entity)->assertRedirect();
        $this->assertSame(0, $extra->slides()->count());
    }

    public function test_leader_can_remove_once_expired_or_flag_cleared(): void
    {
        [$entity, $leader, $show] = $this->setUpLeader();
        $expired = $this->globalSlide(['expires_at' => now()->subDay()]);
        $cleared = $this->globalSlide(['immutable' => false]);
        $show->slides()->attach([$expired->id => ['sort_order' => 1], $cleared->id => ['sort_order' => 2]]);

        $this->remove($leader, $show, $expired, $entity)->assertRedirect();
        $this->remove($leader, $show, $cleared, $entity)->assertRedirect();
        $this->assertSame(0, $show->slides()->count());
    }

    public function test_marking_immutable_restores_slide_to_shows_that_removed_it(): void
    {
        [$entity, $leader, $show] = $this->setUpLeader();
        $admin = User::factory()->create(['role' => 'admin']);
        $slide = $this->globalSlide(['immutable' => false]);

        $this->actingAs($admin)->patch(route('admin.slides.update', $slide), [
            'title' => 'G', 'status' => 'published', 'immutable' => true,
        ])->assertRedirect();

        $this->assertTrue($slide->fresh()->immutable);
        $this->assertTrue($show->slides()->where('slides.id', $slide->id)->exists());
    }
}
