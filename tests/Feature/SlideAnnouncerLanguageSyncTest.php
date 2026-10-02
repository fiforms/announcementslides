<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Language;
use App\Models\SlideAnnouncer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One language shared by the device UI and its slides, changeable from the
 * device or the web, with the later change winning (language_revision).
 */
class SlideAnnouncerLanguageSyncTest extends TestCase
{
    use RefreshDatabase;

    private Language $en;

    private Language $es;

    protected function setUp(): void
    {
        parent::setUp();
        $this->en = Language::create(['abbreviation' => 'en', 'name' => 'English', 'native_name' => 'English']);
        $this->es = Language::create(['abbreviation' => 'es', 'name' => 'Spanish', 'native_name' => 'Español']);
    }

    private function makeDevice(?Language $language = null): SlideAnnouncer
    {
        $entity = Entity::create(['name' => 'Test Church']);

        return SlideAnnouncer::create(['entity_id' => $entity->id, 'name' => 'Lobby', 'language_id' => $language?->id]);
    }

    private function heartbeat(SlideAnnouncer $device, array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->createToken('device')->plainTextToken)
            ->postJson('/api/slide-announcers/heartbeat', $body);
    }

    private function webSetLanguage(SlideAnnouncer $device, Language $language)
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return $this->actingAs($admin)->patch(
            route('slide-announcers.update', ['slideAnnouncer' => $device->id, 'entity_id' => $device->entity_id]),
            ['language_id' => $language->id],
        );
    }

    public function test_device_change_updates_server_and_bumps_revision(): void
    {
        $device = $this->makeDevice($this->en);

        $this->heartbeat($device, ['language_change' => ['code' => 'es', 'base_revision' => 0]])
            ->assertOk()
            ->assertJsonPath('language', 'es')
            ->assertJsonPath('language_revision', 1);

        $this->assertSame($this->es->id, $device->fresh()->language_id);
    }

    public function test_web_change_reaches_device_on_next_heartbeat(): void
    {
        $device = $this->makeDevice($this->en);

        $this->webSetLanguage($device, $this->es)->assertRedirect();

        $this->heartbeat($device, [])
            ->assertOk()
            ->assertJsonPath('language', 'es')
            ->assertJsonPath('language_revision', 1);
    }

    public function test_stale_device_change_loses_to_a_newer_web_change(): void
    {
        $device = $this->makeDevice($this->en);
        $this->webSetLanguage($device, $this->es);

        // Device picked English while still on revision 0, before it heard
        // about the web edit — the web edit is the later change.
        $this->heartbeat($device, ['language_change' => ['code' => 'en', 'base_revision' => 0]])
            ->assertOk()
            ->assertJsonPath('language', 'es')
            ->assertJsonPath('language_revision', 1);
    }

    public function test_unchanged_web_save_does_not_bump_revision(): void
    {
        $device = $this->makeDevice($this->en);

        $this->webSetLanguage($device, $this->en);

        $this->assertSame(0, $device->fresh()->language_revision);
    }

    public function test_unknown_language_code_from_device_is_ignored(): void
    {
        $device = $this->makeDevice($this->en);

        $this->heartbeat($device, ['language_change' => ['code' => 'xx', 'base_revision' => 0]])
            ->assertOk()
            ->assertJsonPath('language', 'en')
            ->assertJsonPath('language_revision', 0);
    }
}
