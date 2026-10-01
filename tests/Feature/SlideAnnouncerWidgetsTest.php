<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Slide;
use App\Models\SlideAnnouncer;
use App\Models\SlideMedia;
use App\Models\User;
use App\Models\Widget;
use App\Services\Widgets\HostResolver;
use App\Services\Widgets\WidgetInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Overlay widgets on Slide Announcer devices: bundles and placements ride
 * the shows sync, and the device fetches widget data through its own
 * token-authenticated endpoint.
 */
class SlideAnnouncerWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private Entity $entity;
    private SlideAnnouncer $device;
    private Slide $slide;
    private SlideMedia $overlay;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->app->instance(HostResolver::class, new class extends HostResolver {
            public function resolve(string $host): array
            {
                return ['142.250.80.46'];
            }
        });

        app(WidgetInstaller::class)->installDirectory(base_path('resources/widgets/calendar'));
        app(WidgetInstaller::class)->installDirectory(base_path('resources/widgets/clock'));

        $this->entity = Entity::create(['name' => 'Test Church']);
        $this->device = SlideAnnouncer::create(['entity_id' => $this->entity->id, 'name' => 'Lobby']);

        $user = User::factory()->create();
        $this->slide = Slide::create(['title' => 'S', 'status' => 'published', 'uploaded_by' => $user->id, 'entity_id' => $this->entity->id]);
        $this->slide->media()->create([
            'media_type' => 'slide', 'filename' => 'a.jpg', 'original_filename' => 'a.jpg',
            'disk_path' => 'slides/a.jpg', 'file_size' => 100, 'mime_type' => 'image/jpeg',
        ]);
        $this->overlay = $this->slide->media()->create([
            'media_type' => 'slide-overlay', 'filename' => 'o.svg', 'original_filename' => 'o.svg',
            'disk_path' => 'slides/o.svg', 'file_size' => 10, 'mime_type' => 'image/svg+xml',
            'overlay_settings' => ['widgets' => [[
                'id' => 'as-el-1', 'widget' => 'calendar', 'x' => 10, 'y' => 20, 'w' => 800, 'h' => 600, 'opacity' => 1,
                'params' => ['ics' => 'https://calendar.google.com/calendar/ical/x/public/basic.ics', 'mode' => 'list'],
            ]]],
        ]);
        $this->entity->mainShow()->slides()->attach($this->slide->id, ['sort_order' => 1]);
    }

    private function asDevice(?SlideAnnouncer $device = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken(($device ?? $this->device)->createToken('device')->plainTextToken);
    }

    private function dataUrl(string $endpoint = 'events'): string
    {
        return "/api/slide-announcers/widget-data/{$this->overlay->id}/as-el-1/{$endpoint}";
    }

    public function test_shows_sync_carries_placements_and_the_bundles_to_mirror(): void
    {
        $response = $this->asDevice()->getJson('/api/slide-announcers/shows')->assertOk();

        $slide = collect($response->json('shows.0.slides'))->firstWhere('id', $this->slide->id);
        $this->assertSame($this->overlay->id, $slide['overlay_media_id']);
        $this->assertSame('calendar', $slide['widgets'][0]['widget']);
        $this->assertSame('1.0.0', $slide['widgets'][0]['version']);
        $this->assertSame(800, $slide['widgets'][0]['w']);
        $this->assertArrayNotHasKey('data_url', $slide['widgets'][0]);

        // Only bundles something actually places — the clock isn't used.
        $bundles = $response->json('widgets');
        $this->assertSame(['calendar'], array_column($bundles, 'slug'));
        $this->assertSame('widget.js', $bundles[0]['entry']);
        $this->assertSame(['icon.png', 'manifest.json', 'widget.js'], array_column($bundles[0]['files'], 'path'));
        // Bundle files come off the public asset route, no token needed.
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->get($bundles[0]['files'][2]['url'])->assertOk();
    }

    public function test_disabled_widgets_are_left_out_of_the_sync(): void
    {
        Widget::where('slug', 'calendar')->update(['enabled' => false]);
        app()->forgetScopedInstances();

        $response = $this->asDevice()->getJson('/api/slide-announcers/shows')->assertOk();

        $this->assertSame([], $response->json('shows.0.slides.0.widgets'));
        $this->assertSame([], $response->json('widgets'));
    }

    public function test_a_device_fetches_data_for_slides_it_syncs(): void
    {
        Http::fake(['*' => Http::response("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nX-WR-CALNAME:Conf\r\nEND:VCALENDAR\r\n")]);

        $this->asDevice()->getJson($this->dataUrl())
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertJsonPath('data.name', 'Conf');
    }

    public function test_a_device_cannot_read_another_entitys_slides(): void
    {
        Http::fake();
        $other = SlideAnnouncer::create(['entity_id' => Entity::create(['name' => 'Elsewhere'])->id, 'name' => 'X']);

        $this->asDevice($other)->getJson($this->dataUrl())->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_expired_slides_and_unauthenticated_calls_are_refused(): void
    {
        Http::fake();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->dataUrl())->assertUnauthorized();

        $this->slide->update(['expires_at' => now()->subDay()]);
        $this->asDevice()->getJson($this->dataUrl())->assertNotFound();
        Http::assertNothingSent();
    }
}
