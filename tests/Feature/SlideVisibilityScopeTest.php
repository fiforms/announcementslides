<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Show;
use App\Models\Slide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Regression cover for the public slide endpoints, which trusted `entity_id`
 * and `show_id` from the query string as if membership in a show were itself
 * permission to see a slide. Every test here fails without
 * Slide::visibleToUser() applied to the query behind it.
 */
class SlideVisibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    private Entity $entity;
    private Show $entityShow;
    private Slide $privateSlide;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $owner = User::factory()->create(['role' => 'contributor']);

        $this->entity     = Entity::create(['name' => 'Private Church']);
        $this->entityShow = Show::mainFor($this->entity);

        $this->privateSlide = $this->makeSlide('Members only', $this->entity->id, $owner->id);
        $this->entityShow->slides()->attach($this->privateSlide->id, ['sort_order' => 1]);
    }

    private function makeSlide(string $title, ?int $entityId, int $uploadedBy, array $attrs = []): Slide
    {
        $slide = Slide::create(array_merge([
            'title'       => $title,
            'status'      => 'published',
            'uploaded_by' => $uploadedBy,
            'entity_id'   => $entityId,
        ], $attrs));

        // The download endpoints stream the real file, so it has to exist on
        // the faked disk or an allowed download 500s instead of 200ing.
        $path = "slides/{$slide->id}.jpg";
        Storage::disk('public')->put($path, 'not-really-a-jpeg');

        $slide->media()->create([
            'media_type' => 'slide',
            'filename'   => "{$slide->id}.jpg", 'original_filename' => "{$slide->id}.jpg",
            'disk_path'  => $path,
            'file_size'  => 17, 'mime_type' => 'image/jpeg',
        ]);

        return $slide;
    }

    private function memberOfOtherEntity(): User
    {
        $user  = User::factory()->create();
        $other = Entity::create(['name' => 'Some Other Church']);
        $user->entities()->attach($other->id, ['role' => 'viewer']);

        return $user;
    }

    // ── The leak: a hand-crafted entity_id / show_id ──────────────────────

    public function test_guest_cannot_see_entity_slides_via_entity_id(): void
    {
        $this->get('/?entity_id='.$this->entity->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Slides/Index')->where('slides', []));
    }

    public function test_guest_cannot_see_entity_slides_in_the_archive(): void
    {
        $this->privateSlide->update(['expires_at' => now()->subDay()]);

        $this->get('/archive?entity_id='.$this->entity->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Slides/Archive')->where('slides.data', []));
    }

    public function test_guest_cannot_download_an_entity_slide(): void
    {
        $this->get("/slides/{$this->privateSlide->id}/download")->assertNotFound();
    }

    public function test_guest_cannot_download_entity_slide_media(): void
    {
        $media = $this->privateSlide->primaryMedia;

        $this->get("/slides/{$this->privateSlide->id}/media/{$media->id}/download")->assertNotFound();
    }

    public function test_guest_cannot_export_an_entity_show_as_a_zip(): void
    {
        $this->get('/slides/download-zip?show_id='.$this->entityShow->id)->assertNotFound();
    }

    public function test_logged_in_non_member_cannot_see_another_entitys_slides(): void
    {
        $this->actingAs($this->memberOfOtherEntity())
            ->get('/?entity_id='.$this->entity->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('slides', []));
    }

    public function test_logged_in_non_member_cannot_download_another_entitys_slide(): void
    {
        $this->actingAs($this->memberOfOtherEntity())
            ->get("/slides/{$this->privateSlide->id}/download")
            ->assertNotFound();
    }

    // ── The other half: legitimate access still works ────────────────────

    public function test_member_sees_their_own_entitys_slides(): void
    {
        $member = User::factory()->create();
        $member->entities()->attach($this->entity->id, ['role' => 'viewer']);

        $this->actingAs($member)
            ->get('/?entity_id='.$this->entity->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->count('slides', 1)
                ->where('slides.0.title', 'Members only'));
    }

    public function test_member_can_download_their_own_entitys_slide(): void
    {
        $member = User::factory()->create();
        $member->entities()->attach($this->entity->id, ['role' => 'viewer']);

        $this->actingAs($member)
            ->get("/slides/{$this->privateSlide->id}/download")
            ->assertOk();
    }

    public function test_guest_can_still_see_and_download_global_slides(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $global = $this->makeSlide('Everyone', null, $admin->id);
        Show::globalBoard()->slides()->attach($global->id, ['sort_order' => 1]);

        $this->get('/')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->count('slides', 1)->where('slides.0.title', 'Everyone'));

        $this->get("/slides/{$global->id}/download")->assertOk();
    }

    /** /archive is public and its cards link at download(), so expiry must not 404. */
    public function test_an_expired_global_slide_is_still_downloadable(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $expired = $this->makeSlide('Last month', null, $admin->id, ['expires_at' => now()->subDay()]);

        $this->get("/slides/{$expired->id}/download")->assertOk();
    }

    /** ...but a slide that has not reached its publish date is not released yet. */
    public function test_a_scheduled_global_slide_is_not_downloadable(): void
    {
        $admin     = User::factory()->create(['role' => 'admin']);
        $scheduled = $this->makeSlide('Next month', null, $admin->id, ['publish_at' => now()->addWeek()]);

        $this->get("/slides/{$scheduled->id}/download")->assertNotFound();
    }
}
