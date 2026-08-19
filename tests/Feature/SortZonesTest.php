<?php

namespace Tests\Feature;

use App\Http\Controllers\ShowController;
use App\Models\Entity;
use App\Models\Show;
use App\Models\Slide;
use App\Models\User;
use App\Support\SortZones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SortZonesTest extends TestCase
{
    use RefreshDatabase;

    private function makeGlobalSlide(User $user, string $title): Slide
    {
        return Slide::create([
            'title' => $title,
            'status' => 'published',
            'uploaded_by' => $user->id,
        ]);
    }

    public function test_global_slide_gets_the_same_sort_order_in_every_show(): void
    {
        $user = User::factory()->create();
        $entityA = Entity::create(['name' => 'Entity A']);
        $entityB = Entity::create(['name' => 'Entity B']);
        $showA = Show::mainFor($entityA);
        $showB = Show::mainFor($entityB);

        $slide = $this->makeGlobalSlide($user, 'Global Slide');
        Show::syncAutoFillForSlide($slide);

        $pivotA = $showA->slides()->where('slides.id', $slide->id)->first()->pivot;
        $pivotB = $showB->slides()->where('slides.id', $slide->id)->first()->pivot;

        $this->assertSame($slide->fresh()->fanout_sort_order, $pivotA->sort_order);
        $this->assertSame($pivotA->sort_order, $pivotB->sort_order);
        $this->assertSame(SortZones::GLOBAL, SortZones::zoneFor($pivotA->sort_order));
        $this->assertTrue((bool) $pivotA->auto_added);
    }

    public function test_newer_global_slide_bubbles_above_older_one_in_every_show(): void
    {
        $user = User::factory()->create();
        $entity = Entity::create(['name' => 'Entity A']);
        $show = Show::mainFor($entity);

        $older = $this->makeGlobalSlide($user, 'Older');
        Show::syncAutoFillForSlide($older);

        $newer = $this->makeGlobalSlide($user, 'Newer');
        Show::syncAutoFillForSlide($newer);

        $this->assertLessThan($older->fresh()->fanout_sort_order, $newer->fresh()->fanout_sort_order);
    }

    public function test_reorder_only_touches_leader_zone_slides(): void
    {
        $user = User::factory()->create();
        $entity = Entity::create(['name' => 'Entity A']);
        $show = Show::mainFor($entity);

        $autoSlide = $this->makeGlobalSlide($user, 'Auto');
        Show::syncAutoFillForSlide($autoSlide);
        $autoSortOrderBefore = $show->slides()->where('slides.id', $autoSlide->id)->first()->pivot->sort_order;

        $manualOne = Slide::create(['title' => 'Manual One', 'status' => 'published', 'uploaded_by' => $user->id, 'entity_id' => $entity->id]);
        $manualTwo = Slide::create(['title' => 'Manual Two', 'status' => 'published', 'uploaded_by' => $user->id, 'entity_id' => $entity->id]);
        $show->slides()->attach($manualOne->id, ['sort_order' => 100, 'auto_added' => false]);
        $show->slides()->attach($manualTwo->id, ['sort_order' => 200, 'auto_added' => false]);

        ShowController::persistLeaderOrder($show, [
            'leader_early' => [$manualTwo->id, $manualOne->id],
        ]);

        $pivots = $show->slides()->get()->keyBy('id');

        [$leaderEarlyStart] = SortZones::bounds(SortZones::LEADER_EARLY);
        $this->assertSame($leaderEarlyStart, $pivots[$manualTwo->id]->pivot->sort_order);
        $this->assertSame($leaderEarlyStart + 1, $pivots[$manualOne->id]->pivot->sort_order);
        $this->assertSame($autoSortOrderBefore, $pivots[$autoSlide->id]->pivot->sort_order);
    }

    public function test_dragging_an_auto_slide_into_a_leader_zone_pins_it_manually(): void
    {
        $user = User::factory()->create();
        $entity = Entity::create(['name' => 'Entity A']);
        $show = Show::mainFor($entity);

        $autoSlide = $this->makeGlobalSlide($user, 'Auto');
        Show::syncAutoFillForSlide($autoSlide);

        ShowController::persistLeaderOrder($show, [
            'leader_late' => [$autoSlide->id],
        ]);

        $pivot = $show->slides()->where('slides.id', $autoSlide->id)->first()->pivot;

        [$leaderLateStart] = SortZones::bounds(SortZones::LEADER_LATE);
        $this->assertSame($leaderLateStart, $pivot->sort_order);
        $this->assertFalse((bool) $pivot->auto_added);
    }
}
