<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\PlayLink;
use App\Models\Slide;
use App\Models\SlideAnnouncer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A slide may have no image: overlay and/or widgets only, or just a title
 * (an invisible placeholder in its show). Covers creating one, removing a
 * slide's image, sharing, and what players receive.
 */
class ImagelessSlideTest extends TestCase
{
    use RefreshDatabase;

    private User $leader;
    private Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();

        $this->entity = Entity::create(['name' => 'Entity A']);
        $this->leader = User::factory()->create();
        $this->leader->entities()->attach($this->entity->id, ['role' => 'admin']);
    }

    private function create(array $data)
    {
        return $this->actingAs($this->leader)->postJson(route('uploads.finalize'), [
            'entity_id' => $this->entity->id, 'add_to_show' => 'main', ...$data,
        ]);
    }

    private function slide(string $title, ?string $mime = 'image/jpeg', bool $overlay = false): Slide
    {
        $slide = Slide::create(['title' => $title, 'status' => 'published', 'uploaded_by' => $this->leader->id, 'entity_id' => $this->entity->id]);
        if ($mime) {
            $slide->media()->create([
                'media_type' => 'slide', 'filename' => "{$title}.jpg", 'original_filename' => "{$title}.jpg",
                'disk_path' => "slides/{$title}.jpg", 'file_size' => 100, 'mime_type' => $mime, 'validation_status' => 'ok',
            ]);
        }
        if ($overlay) {
            $slide->media()->create([
                'media_type' => 'slide-overlay', 'filename' => "{$title}.svg", 'original_filename' => 'o.svg',
                'disk_path' => "slides/{$title}.svg", 'file_size' => 10, 'mime_type' => 'image/svg+xml',
            ]);
        }
        $this->entity->mainShow()->slides()->attach($slide->id, ['sort_order' => $slide->id]);

        return $slide;
    }

    // ── Creating ─────────────────────────────────────────────────────────────

    public function test_a_slide_can_be_created_with_only_a_title_and_lands_in_the_main_show(): void
    {
        $this->create(['title' => 'Placeholder', 'share_nearby' => true])->assertOk()->assertJson(['count' => 1]);

        $slide = Slide::where('title', 'Placeholder')->first();
        $this->assertNull($slide->primaryMedia);
        $this->assertSame(0, $slide->media()->count());
        // Nearby sharing needs an image, so the flag is ignored.
        $this->assertFalse($slide->share_nearby);
        $this->assertTrue($this->entity->mainShow()->slides()->whereKey($slide->id)->exists());
    }

    public function test_a_slide_with_neither_a_file_nor_a_title_is_refused(): void
    {
        foreach ([['title' => ''], []] as $data) {
            // (Web routes answer validation failures with a redirect and session errors.)
            $this->create($data)->assertSessionHasErrors('title');
        }
        $this->assertSame(0, Slide::count());
    }

    public function test_a_file_with_no_title_takes_its_name(): void
    {
        $name = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.jpg';
        Storage::disk('public')->put("slides/{$name}", 'x');

        $this->create(['uploads' => [[
            'filename' => $name, 'disk_path' => "slides/{$name}", 'original_filename' => 'Summer-camp_poster.jpg',
            'file_size' => 1, 'mime_type' => 'image/jpeg',
        ]]])->assertOk();

        $this->assertSame('Summer camp poster', Slide::first()->title);
    }

    // ── Removing the image ───────────────────────────────────────────────────

    public function test_a_slides_image_can_be_removed_leaving_the_slide_in_its_show(): void
    {
        $slide = $this->slide('Camp');
        $media = $slide->primaryMedia;

        $this->actingAs($this->leader)
            ->delete(route('local-slides.media.destroy', ['slide' => $slide->id, 'media' => $media->id, 'entity_id' => $this->entity->id]))
            ->assertSessionHasNoErrors();

        $slide = $slide->fresh();
        $this->assertNull($slide->primaryMedia);
        $this->assertTrue($this->entity->mainShow()->slides()->whereKey($slide->id)->exists());
    }

    public function test_removing_the_image_stops_nearby_sharing(): void
    {
        $slide = $this->slide('Camp');
        $slide->update(['share_nearby' => true]);

        $this->actingAs($this->leader)
            ->delete(route('local-slides.media.destroy', ['slide' => $slide->id, 'media' => $slide->primaryMedia->id, 'entity_id' => $this->entity->id]));

        $this->assertFalse($slide->fresh()->share_nearby);
    }

    public function test_a_slide_with_no_image_cannot_be_shared_nearby(): void
    {
        $slide = $this->slide('Camp', mime: null, overlay: true);

        $this->actingAs($this->leader)
            ->post(route('local-slides.share-nearby', ['slide' => $slide->id, 'entity_id' => $this->entity->id]))
            ->assertSessionHas('error');
        $this->assertFalse($slide->fresh()->share_nearby);
    }

    // ── Players ──────────────────────────────────────────────────────────────

    public function test_players_get_slides_with_an_image_or_an_overlay_and_skip_empty_ones(): void
    {
        $normal = $this->slide('Normal');
        $overlayOnly = $this->slide('Overlay', mime: null, overlay: true);
        $this->slide('Empty', mime: null);

        $link = PlayLink::create(['entity_id' => $this->entity->id, 'title' => 'Hall', 'delay_seconds' => 20]);
        $this->app['auth']->forgetGuards();
        $slides = $this->getJson(route('play.slides', ['token' => $link->token]))->assertOk()->json('slides');

        $this->assertSame([$normal->id, $overlayOnly->id], array_column($slides, 'id'));
        $this->assertNull($slides[1]['file_url']);
        $this->assertNotNull($slides[1]['overlay_url']);

        $device = SlideAnnouncer::create(['entity_id' => $this->entity->id, 'name' => 'Lobby']);
        $this->app['auth']->forgetGuards();
        $sync = $this->withToken($device->createToken('d')->plainTextToken)->getJson('/api/slide-announcers/shows')->assertOk();
        $show = collect($sync->json('shows'))->firstWhere('is_main', true);
        $this->assertSame([$normal->id, $overlayOnly->id], array_column($show['slides'], 'id'));
        $this->assertNull($show['slides'][1]['file_url']);

        // The legacy flat sync (old kiosk builds need a file for every slide).
        $this->app['auth']->forgetGuards();
        $legacy = $this->withToken($device->createToken('d')->plainTextToken)->getJson('/api/slide-announcers/slides')->assertOk();
        $this->assertSame([$normal->id], array_column($legacy->json('slides'), 'id'));
    }
}
