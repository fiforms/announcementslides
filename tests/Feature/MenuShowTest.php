<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\GlobalShowTemplate;
use App\Models\PlayLink;
use App\Models\Show;
use App\Models\Slide;
use App\Models\SlideAnnouncer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shows with play_mode 'menu': a grid to pick from instead of a looping sequence. */
class MenuShowTest extends TestCase
{
    use RefreshDatabase;

    private function slideIn(Show $show, string $title, ?int $entityId = null): Slide
    {
        $slide = Slide::create(['title' => $title, 'status' => 'published', 'uploaded_by' => User::factory()->create()->id, 'entity_id' => $entityId]);
        $slide->media()->create([
            'media_type' => 'slide', 'filename' => 'a.mp4', 'original_filename' => 'a.mp4',
            'disk_path' => 'slides/a.mp4', 'file_size' => 100, 'mime_type' => 'video/mp4',
        ]);
        $show->slides()->attach($slide->id, ['sort_order' => 1]);

        return $slide;
    }

    public function test_shows_default_to_sequence(): void
    {
        $entity = Entity::create(['name' => 'Church']);

        $this->assertSame('sequence', $entity->mainShow()->fresh()->play_mode);
        $this->assertFalse($entity->mainShow()->isMenu());
    }

    public function test_a_leader_sets_play_mode_on_an_extra_show_but_not_on_main(): void
    {
        $entity = Entity::create(['name' => 'Church']);
        $leader = User::factory()->create();
        $leader->entities()->attach($entity->id, ['role' => 'admin']);
        $extra = Show::create(['entity_id' => $entity->id, 'name' => 'Series']);

        $this->actingAs($leader)->patch(route('shows.update', ['show' => $extra->id, 'entity_id' => $entity->id]), ['play_mode' => 'menu'])->assertRedirect();
        $this->assertTrue($extra->fresh()->isMenu());

        $this->actingAs($leader)->patch(route('shows.update', ['show' => $extra->id, 'entity_id' => $entity->id]), ['name' => 'Renamed'])->assertRedirect();
        $this->assertTrue($extra->fresh()->isMenu(), 'a partial patch must not reset the mode');

        $main = $entity->mainShow();
        $this->actingAs($leader)->patch(route('shows.update', ['show' => $main->id, 'entity_id' => $entity->id]), ['play_mode' => 'menu'])->assertRedirect();
        $this->assertSame('sequence', $main->fresh()->play_mode);

        $this->actingAs($leader)->patch(route('shows.update', ['show' => $extra->id, 'entity_id' => $entity->id]), ['play_mode' => 'bogus'])->assertSessionHasErrors('play_mode');
    }

    public function test_promo_play_mode_follows_to_copies_unless_a_leader_changed_theirs(): void
    {
        $template = GlobalShowTemplate::create(['name' => 'Weekly Series']);
        $copies = collect(['A', 'B'])->map(fn ($n) => Show::create([
            'entity_id' => Entity::create(['name' => $n])->id, 'global_template_id' => $template->id, 'name' => $template->name,
        ]));
        $copies[1]->update(['play_mode' => 'menu']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->patch(route('admin.promo-shows.update', $template), ['play_mode' => 'menu'])->assertRedirect();

        $this->assertSame('menu', $template->fresh()->play_mode);
        $this->assertSame('menu', $copies[0]->fresh()->play_mode);

        $this->actingAs($admin)->patch(route('admin.promo-shows.update', $template), ['play_mode' => 'sequence'])->assertRedirect();
        $this->assertSame('sequence', $copies[0]->fresh()->play_mode);
        $this->assertSame('sequence', $copies[1]->fresh()->play_mode);
        $this->assertSame('Weekly Series', $template->fresh()->name, 'changing only the mode keeps the name');

        $this->actingAs($admin)->get(route('admin.promo-shows.index'))
            ->assertInertia(fn ($page) => $page->where('promos.0.play_mode', 'sequence'));
    }

    public function test_the_shared_link_player_and_the_device_sync_carry_the_mode_and_titles(): void
    {
        $entity = Entity::create(['name' => 'Church']);
        $show = Show::create(['entity_id' => $entity->id, 'name' => 'Series', 'play_mode' => 'menu']);
        $this->slideIn($show, 'Week 17', $entity->id);

        $link = PlayLink::create(['entity_id' => $entity->id, 'show_id' => $show->id, 'title' => 'TV', 'delay_seconds' => 10]);
        $this->get(route('play.show', $link->token))
            ->assertInertia(fn ($page) => $page->where('playMode', 'menu')->where('slides.0.title', 'Week 17'));

        $device = SlideAnnouncer::create(['entity_id' => $entity->id, 'name' => 'Lobby']);
        $this->app['auth']->forgetGuards();
        $this->withToken($device->createToken('device')->plainTextToken)
            ->getJson('/api/slide-announcers/shows')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Series', 'play_mode' => 'menu'])
            ->assertJsonFragment(['title' => 'Week 17']);
    }

    public function test_editing_a_promo_slide_returns_to_the_promos_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $slide = Slide::create(['title' => 'Promo', 'status' => 'published', 'uploaded_by' => $admin->id]);

        $this->actingAs($admin)->get(route('admin.slides.edit', ['slide' => $slide->id, 'from' => 'promos']))
            ->assertInertia(fn ($page) => $page->where('from', 'promos'));
        $this->actingAs($admin)->get(route('admin.slides.edit', ['slide' => $slide->id, 'from' => 'https://evil.example']))
            ->assertInertia(fn ($page) => $page->where('from', null));

        $this->actingAs($admin)->patch(route('admin.slides.update', ['slide' => $slide->id, 'from' => 'promos']), ['title' => 'Promo 2', 'status' => 'published'])
            ->assertRedirect(route('admin.promo-shows.index'));
        $this->actingAs($admin)->patch(route('admin.slides.update', ['slide' => $slide->id]), ['title' => 'Promo 3', 'status' => 'published'])
            ->assertRedirect(route('admin.slides.index'));
    }
}
