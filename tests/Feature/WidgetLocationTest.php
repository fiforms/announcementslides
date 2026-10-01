<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\SlideAnnouncer;
use App\Models\User;
use App\Services\Widgets\WidgetInstaller;
use App\Support\WidgetLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Widgets' api.location: the screen's church, else the site default set on
 * Admin → Widgets, else nothing.
 */
class WidgetLocationTest extends TestCase
{
    use RefreshDatabase;

    private const CHARLOTTE = ['name' => 'Charlotte, NC', 'latitude' => 35.2271, 'longitude' => -80.8431];

    private function church(bool $withCoordinates = true): Entity
    {
        return Entity::create([
            'name' => 'Wilmington SDA Church', 'city' => 'Wilmington', 'state' => 'NC',
            'latitude' => $withCoordinates ? 34.2257 : null, 'longitude' => $withCoordinates ? -77.9447 : null,
        ]);
    }

    public function test_a_church_with_coordinates_is_its_own_location(): void
    {
        WidgetLocation::saveDefault(self::CHARLOTTE);

        $this->assertSame(
            ['name' => 'Wilmington, NC', 'latitude' => 34.2257, 'longitude' => -77.9447, 'source' => 'entity'],
            WidgetLocation::for($this->church()),
        );
    }

    public function test_the_default_covers_churches_without_coordinates_and_no_church(): void
    {
        $this->assertNull(WidgetLocation::for($this->church(false)));
        $this->assertNull(WidgetLocation::for(null));

        WidgetLocation::saveDefault(self::CHARLOTTE);

        $this->assertSame(self::CHARLOTTE + ['source' => 'default'], WidgetLocation::for($this->church(false)));
        $this->assertSame(self::CHARLOTTE + ['source' => 'default'], WidgetLocation::for(null));
    }

    public function test_only_admins_set_the_default_and_it_is_validated(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'contributor']))
            ->patch(route('admin.widgets.location'), self::CHARLOTTE)->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patch(route('admin.widgets.location'), ['name' => 'Nowhere', 'latitude' => 120, 'longitude' => 0])
            ->assertSessionHasErrors('latitude');
        $this->actingAs($admin)->patch(route('admin.widgets.location'), ['name' => 'Half', 'latitude' => 35])
            ->assertSessionHasErrors('longitude');

        $this->actingAs($admin)->patch(route('admin.widgets.location'), self::CHARLOTTE)->assertSessionHasNoErrors();
        $this->assertSame(self::CHARLOTTE, WidgetLocation::default());

        $this->actingAs($admin)->patch(route('admin.widgets.location'), ['name' => '', 'latitude' => '', 'longitude' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull(WidgetLocation::default());
    }

    public function test_pages_share_the_location_of_the_church_they_show(): void
    {
        Storage::fake('local');
        app(WidgetInstaller::class)->installDirectory(base_path('resources/widgets/weather'));
        WidgetLocation::saveDefault(self::CHARLOTTE);
        $church = $this->church();

        $this->get('/?entity_id=' . $church->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('widgetLocation.name', 'Wilmington, NC')
            ->where('widgetLocation.source', 'entity'));

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('widgetLocation.name', 'Charlotte, NC')
            ->where('widgetLocation.source', 'default'));
    }

    public function test_devices_sync_their_churchs_location(): void
    {
        WidgetLocation::saveDefault(self::CHARLOTTE);
        $withCoords = SlideAnnouncer::create(['entity_id' => $this->church()->id, 'name' => 'Lobby']);
        $without = SlideAnnouncer::create(['entity_id' => $this->church(false)->id, 'name' => 'Hall']);

        foreach ([[$withCoords, 'Wilmington, NC'], [$without, 'Charlotte, NC']] as [$device, $name]) {
            $this->app['auth']->forgetGuards();
            $this->withToken($device->createToken('device')->plainTextToken)
                ->getJson('/api/slide-announcers/shows')
                ->assertOk()
                ->assertJsonPath('location.name', $name);
        }
    }
}
