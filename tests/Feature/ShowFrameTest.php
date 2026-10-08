<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnail;
use App\Models\Entity;
use App\Models\PlayLink;
use App\Models\Show;
use App\Models\Slide;
use App\Models\SlideAnnouncer;
use App\Models\User;
use App\Services\Widgets\HostResolver;
use App\Services\Widgets\WidgetInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A show's frame: a background (image/video) under every slide and an
 * overlay with widgets over every slide. Covers saving it, who may read its
 * widgets' data, and what the play link and device sync hand to players.
 */
class ShowFrameTest extends TestCase
{
    use RefreshDatabase;

    private const ICS_URL = 'https://calendar.google.com/calendar/ical/x%40group.calendar.google.com/public/basic.ics';
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1920 1080"></svg>';

    private User $leader;
    private Entity $entity;
    private Show $show;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        $this->app->instance(HostResolver::class, new class extends HostResolver {
            public function resolve(string $host): array
            {
                return ['142.250.80.46'];
            }
        });
        app(WidgetInstaller::class)->installDirectory(base_path('resources/widgets/calendar'));
        app(WidgetInstaller::class)->installDirectory(base_path('resources/widgets/clock'));

        $this->entity = Entity::create(['name' => 'Entity A']);
        $this->leader = User::factory()->create();
        $this->leader->entities()->attach($this->entity->id, ['role' => 'admin']);
        $this->show = $this->entity->mainShow();
    }

    private function element(array $overrides = [], string $widget = 'clock'): array
    {
        return array_replace([
            'id' => 'as-el-1', 'type' => 'widget', 'widget' => $widget,
            'x' => 1500, 'y' => 40, 'w' => 380, 'h' => 200, 'opacity' => 1, 'hidden' => false, 'locked' => false,
            'params' => $widget === 'calendar' ? ['ics' => self::ICS_URL] : [],
        ], $overrides);
    }

    private function saveOverlay(array $elements, ?User $as = null, ?int $entityId = null)
    {
        return $this->actingAs($as ?? $this->leader)->put(
            route('shows.overlay.save', ['show' => $this->show->id, 'entity_id' => $entityId ?? $this->entity->id]),
            ['svg' => self::SVG, 'source' => json_encode(['v' => 1, 'canvas' => ['w' => 1920, 'h' => 1080], 'elements' => $elements])]
        );
    }

    /** Uploads a file the way the chunk endpoint leaves it, then attaches it as the background. */
    private function saveBase(string $mime = 'image/jpeg', ?User $as = null)
    {
        $name = Str::uuid() . '.' . ($mime === 'video/mp4' ? 'mp4' : 'jpg');
        Storage::disk('public')->put("slides/{$name}", 'x');

        return $this->actingAs($as ?? $this->leader)->post(
            route('shows.media.store', ['show' => $this->show->id, 'entity_id' => $this->entity->id]),
            ['filename' => $name, 'disk_path' => "slides/{$name}", 'original_filename' => 'bg', 'file_size' => 1, 'mime_type' => $mime]
        );
    }

    private function slide(string $mime, string $title): Slide
    {
        $slide = Slide::create(['title' => $title, 'status' => 'published', 'uploaded_by' => $this->leader->id, 'entity_id' => $this->entity->id]);
        $slide->media()->create([
            'media_type' => 'slide', 'filename' => "{$title}", 'original_filename' => $title,
            'disk_path' => "slides/{$title}", 'file_size' => 100, 'mime_type' => $mime,
        ]);
        $this->show->slides()->attach($slide->id, ['sort_order' => $slide->id]);

        return $slide;
    }

    // ── Saving ───────────────────────────────────────────────────────────────

    public function test_the_overlay_is_saved_like_a_slides_with_cleaned_widget_placements(): void
    {
        $this->saveOverlay([$this->element(['params' => ['junk' => 'x']])])->assertSessionHasNoErrors();

        $media = $this->show->fresh()->overlayMedia;
        $this->assertSame('show-overlay', $media->media_type);
        $this->assertNull($media->slide_id);
        $this->assertSame('clock', $media->overlay_settings['widgets'][0]['widget']);
        $this->assertArrayNotHasKey('junk', $media->overlay_settings['widgets'][0]['params']);

        // Saving again replaces it, and the editor reads back the same source.
        $this->saveOverlay([$this->element(['id' => 'as-el-2'])])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->show->media()->where('media_type', 'show-overlay')->count());
        $response = $this->actingAs($this->leader)
            ->getJson(route('shows.overlay.show', ['show' => $this->show->id, 'entity_id' => $this->entity->id]))
            ->assertOk();
        $this->assertSame('as-el-2', $response->json('source.elements.0.id'));
    }

    public function test_an_invalid_widget_is_rejected(): void
    {
        $this->saveOverlay([$this->element(['widget' => 'nope'])])->assertSessionHasErrors('source');
        $this->assertNull($this->show->fresh()->overlayMedia);
    }

    public function test_the_background_is_one_file_that_replaces_the_last_and_can_be_removed(): void
    {
        $this->saveBase()->assertSessionHasNoErrors();
        $first = $this->show->fresh()->baseMedia;
        $this->assertSame('show-base', $first->media_type);
        Queue::assertPushed(GenerateThumbnail::class);

        $this->saveBase('video/mp4')->assertSessionHasNoErrors();
        $second = $this->show->fresh()->baseMedia;
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, $this->show->media()->count());
        Storage::disk('public')->assertMissing($first->disk_path);

        $this->actingAs($this->leader)
            ->delete(route('shows.media.destroy', ['show' => $this->show->id, 'media' => $second->id, 'entity_id' => $this->entity->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $this->show->media()->count());
        Storage::disk('public')->assertMissing($second->disk_path);
    }

    public function test_only_the_entitys_admins_can_edit_its_shows_frame(): void
    {
        $viewer = User::factory()->create();
        $viewer->entities()->attach($this->entity->id, ['role' => 'viewer']);
        $other = Entity::create(['name' => 'Entity B']);
        $outsider = User::factory()->create();
        $outsider->entities()->attach($other->id, ['role' => 'admin']);

        $this->saveOverlay([$this->element()], $viewer)->assertForbidden();
        $this->saveBase('image/jpeg', $viewer)->assertForbidden();
        // An admin of another entity can't reach this show by naming it.
        $this->saveOverlay([$this->element()], $outsider, $other->id)->assertNotFound();
        $this->assertNull($this->show->fresh()->overlayMedia);
        $this->assertNull($this->show->fresh()->baseMedia);
    }

    // ── Widget data ──────────────────────────────────────────────────────────

    private function placeCalendar(): int
    {
        $this->saveOverlay([$this->element([], 'calendar')])->assertSessionHasNoErrors();
        Http::fake(['calendar.google.com/*' => Http::response("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\nEND:VCALENDAR\r\n")]);

        return $this->show->fresh()->overlayMedia->id;
    }

    public function test_members_read_frame_widget_data_but_outsiders_and_guests_cannot(): void
    {
        $id = $this->placeCalendar();
        $url = route('widget-data.show', ['slideMedia' => $id, 'element' => 'as-el-1', 'endpoint' => 'events']);

        $this->actingAs($this->leader)->getJson($url)->assertOk();
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertNotFound();
    }

    public function test_a_play_link_reads_only_its_own_shows_frame_widget_data(): void
    {
        $id = $this->placeCalendar();
        $link = PlayLink::create(['entity_id' => $this->entity->id, 'title' => 'Hall', 'delay_seconds' => 20]);
        $url = fn () => route('play.widget-data', ['token' => $link->token, 'slideMedia' => $id, 'element' => 'as-el-1', 'endpoint' => 'events']);
        $this->app['auth']->forgetGuards();

        $this->getJson($url())->assertOk();

        $extra = Show::create(['entity_id' => $this->entity->id, 'name' => 'Extra', 'is_main' => false]);
        $link->update(['show_id' => $extra->id]);
        $this->getJson($url())->assertNotFound();
    }

    public function test_a_device_reads_its_own_entitys_frame_widget_data_only(): void
    {
        $id = $this->placeCalendar();
        $device = SlideAnnouncer::create(['entity_id' => $this->entity->id, 'name' => 'Lobby']);
        $stranger = SlideAnnouncer::create(['entity_id' => Entity::create(['name' => 'B'])->id, 'name' => 'Other']);
        $url = "/api/slide-announcers/widget-data/{$id}/as-el-1/events";

        $this->app['auth']->forgetGuards();
        $this->withToken($device->createToken('d')->plainTextToken)->getJson($url)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($stranger->createToken('d')->plainTextToken)->getJson($url)->assertNotFound();
    }

    // ── What players receive ─────────────────────────────────────────────────

    public function test_a_play_link_gets_the_frame_and_skips_video_slides_under_a_background_video(): void
    {
        $this->slide('image/jpeg', 'img');
        $this->slide('video/mp4', 'vid');
        $link = PlayLink::create(['entity_id' => $this->entity->id, 'title' => 'Hall', 'delay_seconds' => 20]);
        $this->app['auth']->forgetGuards();
        $get = fn () => $this->getJson(route('play.slides', ['token' => $link->token]))->assertOk();

        // No frame yet: nothing extra, every slide plays.
        $this->assertNull($get()->json('frame'));
        $this->assertCount(2, $get()->json('slides'));

        // A background image doesn't skip videos.
        $this->saveBase('image/jpeg');
        $this->app['auth']->forgetGuards();
        $this->assertCount(2, $get()->json('slides'));

        // A background video does.
        $this->saveBase('video/mp4');
        $this->placeCalendar();
        $this->app['auth']->forgetGuards();
        $response = $get();
        $this->assertSame(['img'], array_column($response->json('slides'), 'title'));
        $frame = $response->json('frame');
        $this->assertSame('video/mp4', $frame['mime_type']);
        $this->assertSame('calendar', $frame['overlay_widgets'][0]['widget']);
        $this->assertStringContainsString("/play/{$link->token}/widget-data/", $frame['overlay_widgets'][0]['data_url']);
    }

    public function test_the_device_sync_carries_the_frame_and_its_bundles_and_drops_video_slides(): void
    {
        $img = $this->slide('image/jpeg', 'img');
        $this->slide('video/mp4', 'vid');
        $this->saveBase('video/mp4');
        $this->saveOverlay([$this->element()])->assertSessionHasNoErrors();
        $device = SlideAnnouncer::create(['entity_id' => $this->entity->id, 'name' => 'Lobby']);
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($device->createToken('d')->plainTextToken)->getJson('/api/slide-announcers/shows')->assertOk();

        $show = collect($response->json('shows'))->firstWhere('id', (string) $this->show->id);
        $this->assertSame([$img->id], array_column($show['slides'], 'id'));
        $this->assertSame('video/mp4', $show['frame']['mime_type']);
        $this->assertSame($this->show->fresh()->overlayMedia->id, $show['frame']['overlay_media_id']);
        $this->assertSame('clock', $show['frame']['widgets'][0]['widget']);
        $this->assertContains('clock', array_column($response->json('widgets'), 'slug'));
    }

    // ── YouTube background ───────────────────────────────────────────────────

    private function saveYoutube(string $url, bool $muted = false)
    {
        return $this->actingAs($this->leader)->put(
            route('shows.youtube.save', ['show' => $this->show->id, 'entity_id' => $this->entity->id]),
            ['url' => $url, 'muted' => $muted]
        );
    }

    public function test_a_youtube_background_stores_only_ids_and_replaces_an_uploaded_one(): void
    {
        $this->saveBase('image/jpeg');

        $this->saveYoutube('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=5s', muted: true)->assertSessionHasNoErrors();

        $show = $this->show->fresh();
        $this->assertSame(['video_id' => 'dQw4w9WgXcQ', 'muted' => true], $show->frame_youtube);
        $this->assertNull($show->baseMedia);

        // ...and uploading a file replaces the YouTube one.
        $this->saveBase('image/jpeg');
        $this->assertNull($this->show->fresh()->frame_youtube);
        $this->assertNotNull($this->show->fresh()->baseMedia);

        $this->saveYoutube('')->assertSessionHasNoErrors();
        $this->assertNull($this->show->fresh()->frame_youtube);
    }

    public function test_a_link_that_is_not_youtube_is_rejected(): void
    {
        $this->saveYoutube('https://evil.example/watch?v=dQw4w9WgXcQ')->assertSessionHasErrors('url');
        $this->saveYoutube('javascript:alert(1)')->assertSessionHasErrors('url');
        $this->assertNull($this->show->fresh()->frame_youtube);
    }

    public function test_only_the_entitys_admins_can_set_a_youtube_background(): void
    {
        $viewer = User::factory()->create();
        $viewer->entities()->attach($this->entity->id, ['role' => 'viewer']);

        $this->actingAs($viewer)->put(
            route('shows.youtube.save', ['show' => $this->show->id, 'entity_id' => $this->entity->id]),
            ['url' => 'dQw4w9WgXcQ']
        )->assertForbidden();
    }

    public function test_players_get_a_built_embed_url_and_video_slides_are_skipped(): void
    {
        $img = $this->slide('image/jpeg', 'img');
        $this->slide('video/mp4', 'vid');
        $this->saveYoutube('PLabcdefghijklmnopqrstuvwxyz012345', muted: true);
        $link = PlayLink::create(['entity_id' => $this->entity->id, 'title' => 'Hall', 'delay_seconds' => 20]);
        $this->app['auth']->forgetGuards();

        $response = $this->getJson(route('play.slides', ['token' => $link->token]))->assertOk();
        $this->assertSame([$img->id], array_column($response->json('slides'), 'id'));
        $url = $response->json('frame.youtube_url');
        $this->assertStringStartsWith('https://www.youtube-nocookie.com/embed/videoseries?list=PLabcdefghijklmnopqrstuvwxyz012345&', $url);
        $this->assertStringContainsString('loop=1', $url);
        $this->assertStringContainsString('mute=1', $url);
        $this->assertNull($response->json('frame.file_url'));

        $device = SlideAnnouncer::create(['entity_id' => $this->entity->id, 'name' => 'Lobby']);
        $this->app['auth']->forgetGuards();
        $sync = $this->withToken($device->createToken('d')->plainTextToken)->getJson('/api/slide-announcers/shows')->assertOk();
        $show = collect($sync->json('shows'))->firstWhere('id', (string) $this->show->id);
        $this->assertSame($url, $show['frame']['youtube_url']);
        $this->assertSame([$img->id], array_column($show['slides'], 'id'));
    }
}
