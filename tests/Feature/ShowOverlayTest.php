<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\PlayLink;
use App\Models\Show;
use App\Models\SlideAnnouncer;
use App\Models\User;
use App\Services\Widgets\HostResolver;
use App\Services\Widgets\WidgetInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A show's pinned widget layer: saving/validating it, and who may read its
 * widgets' data (members' browsers, shared play links, paired devices).
 */
class ShowOverlayTest extends TestCase
{
    use RefreshDatabase;

    private const ICS_URL = 'https://calendar.google.com/calendar/ical/x%40group.calendar.google.com/public/basic.ics';

    private User $leader;
    private Entity $entity;
    private Show $show;

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

    private function save(array $elements, ?User $as = null, ?Show $show = null)
    {
        $show ??= $this->show;

        return $this->actingAs($as ?? $this->leader)->put(
            route('shows.overlay.save', ['show' => $show->id, 'entity_id' => $show->entity_id ?? $this->entity->id]),
            ['source' => json_encode(['v' => 1, 'canvas' => ['w' => 1920, 'h' => 1080], 'elements' => $elements])]
        );
    }

    public function test_saving_stores_cleaned_placements_and_the_editor_source(): void
    {
        $this->save([$this->element(['params' => ['junk' => 'dropped']])])->assertSessionHasNoErrors();

        $overlay = $this->show->fresh()->overlay;
        $this->assertSame('clock', $overlay->overlay_settings['widgets'][0]['widget']);
        $this->assertSame(1500.0, (float) $overlay->overlay_settings['widgets'][0]['x']);
        $this->assertArrayNotHasKey('junk', $overlay->overlay_settings['widgets'][0]['params']);
        $this->assertSame('widget', $overlay->source['elements'][0]['type']);

        $response = $this->actingAs($this->leader)
            ->getJson(route('shows.overlay.show', ['show' => $this->show->id, 'entity_id' => $this->entity->id]))
            ->assertOk();
        $this->assertSame('as-el-1', $response->json('source.elements.0.id'));
        $this->assertContains('clock', array_column($response->json('widgets'), 'slug'));
    }

    public function test_saving_again_replaces_and_an_empty_save_removes_the_layer(): void
    {
        $this->save([$this->element()]);
        $this->save([$this->element(['id' => 'as-el-2', 'x' => 10])]);
        $this->assertSame(1, $this->show->overlay()->count());
        $this->assertSame('as-el-2', $this->show->fresh()->overlay->overlay_settings['widgets'][0]['id']);

        $this->save([])->assertSessionHasNoErrors();
        $this->assertNull($this->show->fresh()->overlay);
    }

    public function test_non_widget_elements_are_ignored(): void
    {
        $this->save([['id' => 'as-el-9', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10], $this->element()]);

        $this->assertCount(1, $this->show->fresh()->overlay->source['elements']);
    }

    public function test_an_invalid_widget_is_rejected(): void
    {
        $this->save([$this->element(['widget' => 'nope'])])->assertSessionHasErrors('source');
        $this->save([$this->element(['w' => 0])])->assertSessionHasErrors('source');
        $this->assertNull($this->show->fresh()->overlay);
    }

    public function test_only_the_entitys_admins_can_edit_its_shows(): void
    {
        $member = User::factory()->create();
        $member->entities()->attach($this->entity->id, ['role' => 'viewer']);
        $other = Entity::create(['name' => 'Entity B']);
        $outsider = User::factory()->create();
        $outsider->entities()->attach($other->id, ['role' => 'admin']);

        $this->save([$this->element()], $member)->assertForbidden();
        // An admin of another entity can't reach this show by naming it.
        $this->actingAs($outsider)->put(
            route('shows.overlay.save', ['show' => $this->show->id, 'entity_id' => $other->id]),
            ['source' => json_encode(['v' => 1, 'elements' => [$this->element()]])]
        )->assertNotFound();
        $this->actingAs($outsider)->getJson(route('shows.overlay.show', ['show' => $this->show->id, 'entity_id' => $other->id]))->assertNotFound();
        $this->assertNull($this->show->fresh()->overlay);
    }

    // ── Widget data ──────────────────────────────────────────────────────────

    private function placeCalendar(): void
    {
        $this->save([$this->element([], 'calendar')])->assertSessionHasNoErrors();
        Http::fake(['calendar.google.com/*' => Http::response("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\nEND:VCALENDAR\r\n")]);
    }

    public function test_members_read_show_widget_data_but_outsiders_and_guests_cannot(): void
    {
        $this->placeCalendar();
        $url = route('widget-data.show-overlay', ['show' => $this->show->id, 'element' => 'as-el-1', 'endpoint' => 'events']);

        $this->actingAs($this->leader)->getJson($url)->assertOk();
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertNotFound();
        $this->actingAs($this->leader)->getJson(route('widget-data.show-overlay', ['show' => $this->show->id, 'element' => 'as-el-9', 'endpoint' => 'events']))->assertNotFound();
    }

    public function test_a_play_link_reads_only_its_own_shows_widget_data(): void
    {
        $this->placeCalendar();
        $link = PlayLink::create([
            'entity_id' => $this->entity->id, 'title' => 'Hall', 'delay_seconds' => 20,
            'created_by' => $this->leader->id,
        ]);
        $this->app['auth']->forgetGuards();

        $this->getJson(route('play.widget-data-show', ['token' => $link->token, 'element' => 'as-el-1', 'endpoint' => 'events']))->assertOk();

        // A link following a different show of the entity has no such layer.
        $extra = Show::create(['entity_id' => $this->entity->id, 'name' => 'Extra', 'is_main' => false]);
        $link->update(['show_id' => $extra->id]);
        $this->getJson(route('play.widget-data-show', ['token' => $link->token, 'element' => 'as-el-1', 'endpoint' => 'events']))->assertNotFound();

        $link->update(['show_id' => null, 'revoked_at' => now()]);
        $this->getJson(route('play.widget-data-show', ['token' => $link->token, 'element' => 'as-el-1', 'endpoint' => 'events']))->assertNotFound();
    }

    public function test_a_device_reads_its_own_entitys_show_widget_data_only(): void
    {
        $this->placeCalendar();
        $device = SlideAnnouncer::create(['entity_id' => $this->entity->id, 'name' => 'Lobby']);
        $stranger = SlideAnnouncer::create(['entity_id' => Entity::create(['name' => 'B'])->id, 'name' => 'Other']);
        $url = "/api/slide-announcers/show-widget-data/{$this->show->id}/as-el-1/events";

        $this->app['auth']->forgetGuards();
        $this->withToken($device->createToken('d')->plainTextToken)->getJson($url)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($stranger->createToken('d')->plainTextToken)->getJson($url)->assertNotFound();
    }
}
