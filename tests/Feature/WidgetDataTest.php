<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Slide;
use App\Models\SlideMedia;
use App\Models\User;
use App\Models\Widget;
use App\Services\Widgets\HostResolver;
use App\Services\Widgets\WidgetInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WidgetDataTest extends TestCase
{
    use RefreshDatabase;

    private const ICS_URL = 'https://calendar.google.com/calendar/ical/carolinasda.org_47pbgk242tl968925qivqas0s8%40group.calendar.google.com/public/basic.ics';

    /** @var array<string, string[]> */
    private array $dns = [];

    private User $user;
    private Entity $entity;
    private Slide $slide;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();

        $this->dns = [
            'calendar.google.com' => ['142.250.80.46'], 'api.example.com' => ['93.184.216.34'],
            'geocoding-api.open-meteo.com' => ['51.161.5.1'], 'api.open-meteo.com' => ['51.161.5.2'],
        ];
        $test = $this;
        $this->app->instance(HostResolver::class, new class($test) extends HostResolver {
            public function __construct(private $test) {}

            public function resolve(string $host): array
            {
                return $this->test->dnsFor($host);
            }
        });

        app(WidgetInstaller::class)->installDirectory(base_path('resources/widgets/calendar'));
        app(WidgetInstaller::class)->installDirectory(base_path('resources/widgets/clock'));
        app(WidgetInstaller::class)->installDirectory(base_path('resources/widgets/weather'));

        $this->user = User::factory()->create();
        $this->entity = Entity::create(['name' => 'Entity A']);
        $this->user->entities()->attach($this->entity->id, ['role' => 'admin']);

        $this->slide = Slide::create([
            'title' => 'S', 'status' => 'published',
            'uploaded_by' => $this->user->id, 'entity_id' => $this->entity->id,
        ]);
        $this->slide->media()->create([
            'media_type' => 'slide', 'filename' => 'a.jpg', 'original_filename' => 'a.jpg',
            'disk_path' => 'slides/a.jpg', 'file_size' => 100, 'mime_type' => 'image/jpeg',
        ]);
    }

    public function dnsFor(string $host): array
    {
        return $this->dns[$host] ?? [];
    }

    private function ics(): string
    {
        $start = now()->addDays(3)->format('Ymd');
        $end = now()->addDays(4)->format('Ymd');
        $timed = now()->addDays(5)->setTime(15, 0)->utc()->format('Ymd\THis\Z');

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\nX-WR-CALNAME:Conference Calendar\r\n"
            . "BEGIN:VEVENT\r\nUID:a\r\nDTSTART;VALUE=DATE:{$start}\r\nDTEND;VALUE=DATE:{$end}\r\nSUMMARY:Camp Meeting <script>\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:b\r\nDTSTART:{$timed}\r\nDURATION:PT1H\r\nSUMMARY:Board\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }

    private function widgetElement(array $params, array $overrides = []): array
    {
        return array_replace([
            'id' => 'as-el-1', 'type' => 'widget', 'widget' => 'calendar',
            'x' => 100, 'y' => 100, 'w' => 800, 'h' => 600, 'opacity' => 1, 'hidden' => false, 'locked' => false,
            'params' => $params,
        ], $overrides);
    }

    private function saveOverlay(array $elements)
    {
        return $this->actingAs($this->user)->put(
            route('local-slides.overlay.save', ['slide' => $this->slide->id, 'entity_id' => $this->entity->id]),
            [
                'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="1920" height="1080" viewBox="0 0 1920 1080"></svg>',
                'source' => json_encode(['v' => 1, 'canvas' => ['w' => 1920, 'h' => 1080], 'elements' => $elements]),
            ]
        );
    }

    private function overlay(): SlideMedia
    {
        return $this->slide->fresh()->overlayMedia;
    }

    private function dataUrl(string $endpoint = 'events'): string
    {
        return route('widget-data.show', ['slideMedia' => $this->overlay()->id, 'element' => 'as-el-1', 'endpoint' => $endpoint]);
    }

    // ── Saving placements ────────────────────────────────────────────────────

    public function test_saving_a_widget_only_overlay_stores_cleaned_placements(): void
    {
        $this->saveOverlay([$this->widgetElement(['ics' => 'webcal://calendar.google.com/calendar/ical/x%40group.calendar.google.com/public/basic.ics', 'junk' => 'dropped'])])
            ->assertSessionHasNoErrors();

        $placement = $this->overlay()->overlay_settings['widgets'][0];
        $this->assertSame('calendar', $placement['widget']);
        $this->assertSame('https://calendar.google.com/calendar/ical/x%40group.calendar.google.com/public/basic.ics', $placement['params']['ics']);
        $this->assertSame('list', $placement['params']['mode']);
        $this->assertArrayNotHasKey('junk', $placement['params']);

        $forPlayer = $this->slide->fresh()->overlay_widgets;
        $this->assertCount(1, $forPlayer);
        $this->assertStringContainsString('/widget-assets/calendar/1.0.1/widget.js', $forPlayer[0]['entry_url']);
    }

    public function test_an_ics_url_off_the_allowlist_cannot_be_saved(): void
    {
        $this->saveOverlay([$this->widgetElement(['ics' => 'https://evil.example/calendar/ical/x.ics'])])
            ->assertSessionHasErrors('source');
        $this->saveOverlay([$this->widgetElement(['ics' => 'https://calendar.google.com.evil.example/calendar/ical/x.ics'])])
            ->assertSessionHasErrors('source');
        $this->saveOverlay([$this->widgetElement(['ics' => 'http://calendar.google.com/calendar/ical/x.ics'])])
            ->assertSessionHasErrors('source');
        $this->assertNull($this->slide->fresh()->overlayMedia);
    }

    public function test_an_admin_added_prefix_is_honoured(): void
    {
        Widget::where('slug', 'calendar')->update(['extra_allow' => json_encode(['ics' => ['https://church.example/feeds/']])]);

        $this->saveOverlay([$this->widgetElement(['ics' => 'https://church.example/feeds/main.ics'])])->assertSessionHasNoErrors();
    }

    public function test_unknown_widgets_and_bad_params_are_rejected(): void
    {
        $this->saveOverlay([$this->widgetElement([], ['widget' => 'nope'])])->assertSessionHasErrors('source');
        $this->saveOverlay([$this->widgetElement(['mode' => 'carousel'])])->assertSessionHasErrors('source');
        $this->saveOverlay([$this->widgetElement(['color' => 'red; background:url(x)'])])->assertSessionHasErrors('source');
    }

    // ── Fetching data ────────────────────────────────────────────────────────

    public function test_events_are_fetched_parsed_cached_and_served_as_safe_json(): void
    {
        Http::fake(['calendar.google.com/*' => Http::response($this->ics(), 200, ['Content-Type' => 'text/calendar'])]);
        $this->saveOverlay([$this->widgetElement(['ics' => self::ICS_URL])]);

        $response = $this->get($this->dataUrl())
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
            ->assertJsonPath('data.name', 'Conference Calendar')
            ->assertJsonPath('data.events.0.title', 'Camp Meeting <script>')
            ->assertJsonPath('data.events.0.all_day', true)
            ->assertJsonPath('data.events.1.title', 'Board')
            ->assertJsonPath('stale', false);
        $this->assertSame(now()->addDays(3)->format('Y-m-d'), $response->json('data.events.0.start'));

        $this->get($this->dataUrl())->assertOk();
        Http::assertSentCount(1);
    }

    public function test_public_slides_serve_widget_data_to_guests_but_entity_slides_do_not(): void
    {
        Http::fake(['*' => Http::response($this->ics())]);
        $this->saveOverlay([$this->widgetElement(['ics' => self::ICS_URL])]);
        $url = $this->dataUrl();

        $this->app['auth']->forgetGuards();
        $this->get($url)->assertNotFound();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs($this->user)->get($url)->assertOk();

        $this->slide->update(['entity_id' => null]);
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk();
    }

    public function test_html_masquerading_as_a_calendar_is_rejected(): void
    {
        Http::fake(['*' => Http::response('<html><script>alert(1)</script></html>', 200, ['Content-Type' => 'text/calendar'])]);
        $this->saveOverlay([$this->widgetElement(['ics' => self::ICS_URL])]);

        $this->get($this->dataUrl())->assertStatus(502)->assertJsonPath('error', 'invalid_response');
    }

    public function test_hosts_resolving_to_private_addresses_are_never_contacted(): void
    {
        Http::fake();
        $this->saveOverlay([$this->widgetElement(['ics' => self::ICS_URL])]);

        foreach ([['127.0.0.1'], ['169.254.169.254'], ['10.0.0.5'], ['::ffff:127.0.0.1'], ['142.250.80.46', '192.168.1.10']] as $answer) {
            $this->dns['calendar.google.com'] = $answer;
            $this->get($this->dataUrl())->assertStatus(502)->assertJsonPath('error', 'blocked_address');
        }
        Http::assertNothingSent();
    }

    public function test_redirects_are_rechecked_against_the_allowlist_and_address_rules(): void
    {
        $this->saveOverlay([$this->widgetElement(['ics' => self::ICS_URL])]);

        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://evil.example/x.ics'])]);
        $this->get($this->dataUrl())->assertStatus(502)->assertJsonPath('error', 'blocked_redirect');

        $this->dns['evil.calendar.google.com'] = ['10.0.0.1'];
        Http::fake(['*' => Http::sequence()
            ->push('', 302, ['Location' => '/calendar/ical/moved.ics'])
            ->push('', 302, ['Location' => 'https://calendar.google.com:8080/calendar/ical/x.ics'])]);
        $this->get($this->dataUrl())->assertStatus(502)->assertJsonPath('error', 'blocked_redirect');
    }

    public function test_oversized_responses_are_cut_off(): void
    {
        config(['widgets.fetch.max_bytes.ical' => 1000]);
        Http::fake(['*' => Http::response("BEGIN:VCALENDAR\r\n" . str_repeat('X', 5000))]);
        $this->saveOverlay([$this->widgetElement(['ics' => self::ICS_URL])]);

        $this->get($this->dataUrl())->assertStatus(502)->assertJsonPath('error', 'too_large');
    }

    public function test_last_good_data_is_served_while_the_upstream_fails(): void
    {
        $this->saveOverlay([$this->widgetElement(['ics' => self::ICS_URL])]);
        Http::fake(['*' => Http::sequence()->push($this->ics())->push('down', 500)]);

        $this->get($this->dataUrl())->assertOk()->assertJsonPath('stale', false);
        $this->travel(20)->minutes();
        $this->get($this->dataUrl())->assertOk()->assertJsonPath('stale', true)->assertJsonPath('data.name', 'Conference Calendar');
    }

    public function test_an_allowlist_narrowed_after_saving_is_enforced_at_fetch_time(): void
    {
        Widget::where('slug', 'calendar')->update(['extra_allow' => json_encode(['ics' => ['https://church.example/feeds/']])]);
        $this->saveOverlay([$this->widgetElement(['ics' => 'https://church.example/feeds/main.ics'])])->assertSessionHasNoErrors();
        Widget::where('slug', 'calendar')->update(['extra_allow' => null]);
        Http::fake();

        $this->get($this->dataUrl())->assertStatus(502)->assertJsonPath('error', 'blocked_url');
        Http::assertNothingSent();
    }

    public function test_disabled_widgets_and_unknown_endpoints_get_404(): void
    {
        Http::fake();
        $this->saveOverlay([$this->widgetElement(['ics' => self::ICS_URL])]);

        $this->get($this->dataUrl('nope'))->assertNotFound();
        Widget::where('slug', 'calendar')->update(['enabled' => false]);
        $this->get($this->dataUrl())->assertNotFound();
        app()->forgetScopedInstances();
        $this->assertSame([], $this->slide->fresh()->overlay_widgets);
    }

    public function test_an_empty_feed_url_reports_not_configured_without_fetching(): void
    {
        Http::fake();
        $this->saveOverlay([$this->widgetElement(['ics' => ''])])->assertSessionHasNoErrors();

        $this->get($this->dataUrl())->assertStatus(422)->assertJsonPath('error', 'not_configured');
        Http::assertNothingSent();
    }

    public function test_fixed_host_templates_cannot_be_steered_by_parameters(): void
    {
        $dir = sys_get_temp_dir() . '/widget-weather-' . uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/widget.js", 'export function mount() {}');
        file_put_contents("{$dir}/icon.png", 'png');
        file_put_contents("{$dir}/manifest.json", json_encode([
            'id' => 'quotes', 'name' => 'Quotes', 'version' => '1.0.0', 'entry' => 'widget.js', 'icon' => 'icon.png',
            'parameters' => ['symbol' => ['type' => 'string', 'default' => 'X']],
            'settings' => ['api_key' => ['label' => 'API key']],
            'endpoints' => ['quote' => ['url' => 'https://api.example.com/v1/{symbol}?key={secret:api_key}', 'expect' => 'json']],
        ]));
        $widget = app(WidgetInstaller::class)->installDirectory($dir);
        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
        $widget->update(['settings' => ['api_key' => 's3cr3t']]);

        Http::fake(['api.example.com/*' => Http::response(['price' => 1])]);
        $this->saveOverlay([$this->widgetElement(['symbol' => '@evil.example/../../x?'], ['widget' => 'quotes'])])->assertSessionHasNoErrors();

        $this->get($this->dataUrl('quote'))->assertOk()->assertJsonPath('data.price', 1);
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.example.com/v1/%40evil.example%2F..%2F..%2Fx%3F?key=s3cr3t');
        });
    }

    public function test_preview_requires_an_overlay_editor_and_validates_params(): void
    {
        Http::fake(['*' => Http::response($this->ics())]);
        $body = ['widget' => 'calendar', 'endpoint' => 'events', 'params' => ['ics' => self::ICS_URL]];

        $this->actingAs(User::factory()->create(['role' => 'contributor']))
            ->postJson(route('widget-data.preview'), $body)->assertForbidden();

        $this->actingAs($this->user)->postJson(route('widget-data.preview'), $body)
            ->assertOk()->assertJsonPath('data.name', 'Conference Calendar');

        $this->actingAs($this->user)->postJson(route('widget-data.preview'), ['params' => ['ics' => 'https://evil.example/x.ics']] + $body)
            ->assertStatus(422)->assertJsonPath('error', 'invalid_params');
    }

    // ── Runtime args (weather: geocode, then forecast by lat/lon) ────────────

    private function saveWeather(array $params = ['zip' => '90210']): void
    {
        $this->saveOverlay([$this->widgetElement($params, ['widget' => 'weather'])])->assertSessionHasNoErrors();
    }

    public function test_weather_geocodes_the_saved_zip_then_forecasts_by_runtime_args(): void
    {
        Http::fake([
            'geocoding-api.open-meteo.com/*' => Http::response(['results' => [['name' => 'Beverly Hills', 'latitude' => 34.07362, 'longitude' => -118.40036]]]),
            'api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 79.1]]),
        ]);
        $this->saveWeather();

        $this->get($this->dataUrl('geocode'))->assertOk()->assertJsonPath('data.results.0.name', 'Beverly Hills');
        $this->get($this->dataUrl('forecast') . '?args[lat]=34.07&args[lon]=-118.4')
            ->assertOk()->assertJsonPath('data.current.temperature_2m', 79.1);

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://geocoding-api.open-meteo.com/v1/search?name=90210&count=1&countryCode=US'));
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.open-meteo.com/v1/forecast?latitude=34.07&longitude=-118.4&')
            && str_contains($r->url(), 'temperature_unit=fahrenheit'));
    }

    public function test_runtime_args_are_validated_and_cannot_steer_the_request(): void
    {
        Http::fake();
        $this->saveWeather();

        foreach ([
            '',                                              // missing
            '?args[lat]=34&args[lon]=-200',                  // out of range
            '?args[lat]=abc&args[lon]=1',                    // not a number
            '?args[lat]=1%26host%3Devil.example&args[lon]=1', // injection attempt
        ] as $query) {
            $this->get($this->dataUrl('forecast') . $query)->assertStatus(422)->assertJsonPath('error', 'invalid_args');
        }
        Http::assertNothingSent();
    }

    public function test_args_are_part_of_the_cache_key(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::sequence()->push(['n' => 1])->push(['n' => 2])]);
        $this->saveWeather();

        $this->get($this->dataUrl('forecast') . '?args[lat]=34.07&args[lon]=-118.4')->assertJsonPath('data.n', 1);
        $this->get($this->dataUrl('forecast') . '?args[lat]=34.07&args[lon]=-118.4')->assertJsonPath('data.n', 1);
        $this->get($this->dataUrl('forecast') . '?args[lat]=35.5&args[lon]=-80')->assertJsonPath('data.n', 2);
        Http::assertSentCount(2);
    }

    public function test_preview_passes_runtime_args(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response(['ok' => true])]);

        $this->actingAs($this->user)->postJson(route('widget-data.preview'), [
            'widget' => 'weather', 'endpoint' => 'forecast', 'params' => ['zip' => '28401'], 'args' => ['lat' => 34.23, 'lon' => -77.94],
        ])->assertOk()->assertJsonPath('data.ok', true);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'latitude=34.23&longitude=-77.94'));
    }
}
