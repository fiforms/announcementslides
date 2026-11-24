<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Notification;
use App\Models\SlideAnnouncer;
use App\Models\User;
use App\Support\NotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Entity $entity;

    private User $leader;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entity = Entity::create(['name' => 'Alpha Church']);
        $this->leader = User::factory()->create();
        $this->leader->entities()->attach($this->entity->id, ['role' => 'admin']);
        $this->editor = User::factory()->create();
        $this->editor->entities()->attach($this->entity->id, ['role' => 'editor']);
    }

    private function device(array $attrs = []): SlideAnnouncer
    {
        return SlideAnnouncer::create(['entity_id' => $this->entity->id, 'name' => 'Lobby'] + $attrs);
    }

    private function heartbeat(SlideAnnouncer $device, array $body = [])
    {
        return $this->withToken($device->createToken('device')->plainTextToken)
            ->postJson('/api/slide-announcers/heartbeat', $body)->assertOk();
    }

    private function optInToStatus(User $user): void
    {
        NotificationPreferences::save($user, ['device_status' => ['inbox' => true, 'email' => 'none']]);
    }

    public function test_hot_heartbeat_notifies_managers_once_until_it_cools_down(): void
    {
        $device = $this->device();
        $follower = User::factory()->create();
        $follower->entities()->attach($this->entity->id, ['role' => 'viewer']);

        $this->heartbeat($device, ['cpu_temp_c' => 68.0]);
        $this->assertSame(0, Notification::count());

        $this->heartbeat($device, ['cpu_temp_c' => 72.4]);
        $n = Notification::forUser($this->leader)->sole();
        $this->assertSame('device_hot', $n->type);
        $this->assertSame('device_temperature', $n->category);
        $this->assertSame(['device' => 'Lobby', 'entity' => 'Alpha Church', 'temp' => 72, 'limit' => 70], $n->data['params']);
        $this->assertSame(1, Notification::forUser($this->editor)->count());
        $this->assertSame(0, Notification::forUser($follower)->count());

        // Still hot, or cooled only slightly: no repeat.
        $this->heartbeat($device, ['cpu_temp_c' => 75.0]);
        $this->heartbeat($device, ['cpu_temp_c' => 67.0]);
        $this->assertSame(1, Notification::forUser($this->leader)->count());

        // Cooled well below the limit, but too soon after the notice: still held.
        $this->heartbeat($device, ['cpu_temp_c' => 50.0]);
        $this->heartbeat($device, ['cpu_temp_c' => 71.0]);
        $this->assertSame(1, Notification::forUser($this->leader)->count());

        // Cooled down after the hold: re-armed.
        $this->travel(2)->hours();
        $this->heartbeat($device, ['cpu_temp_c' => 50.0]);
        $this->assertNull($device->fresh()->temp_alert_at);
        $this->heartbeat($device, ['cpu_temp_c' => 71.0]);
        $this->assertSame(2, Notification::forUser($this->leader)->count());
    }

    public function test_temperature_limit_comes_from_admin_setting(): void
    {
        \App\Support\NotificationSettings::save(['device_temp_threshold_c' => 80]);
        $device = $this->device();

        $this->heartbeat($device, ['cpu_temp_c' => 75.0]);
        $this->assertSame(0, Notification::count());
        $this->heartbeat($device, ['cpu_temp_c' => 80.0]);
        $this->assertSame(80, Notification::forUser($this->leader)->sole()->data['params']['limit']);
    }

    public function test_status_notifications_are_opt_in(): void
    {
        $this->device(['last_seen_at' => now()->subMinutes(20)]);

        $this->artisan('notifications:device-status')->assertSuccessful();

        $this->assertSame(0, Notification::count());
    }

    public function test_offline_after_missed_heartbeats_and_back_online(): void
    {
        $this->optInToStatus($this->leader);
        $device = $this->device(['last_seen_at' => now()->subMinutes(10)]);
        $revoked = $this->device(['name' => 'Old', 'last_seen_at' => now()->subMinutes(30), 'revoked_at' => now()]);

        // Two missed beats: not yet.
        $this->artisan('notifications:device-status');
        $this->assertSame(0, Notification::count());

        $this->travel(6)->minutes();
        $this->artisan('notifications:device-status');
        $this->artisan('notifications:device-status');
        $n = Notification::forUser($this->leader)->sole();
        $this->assertSame('device_offline', $n->type);
        $this->assertSame(3, $n->data['params']['missed']);
        $this->assertStringContainsString('/slide-announcers/'.$device->id, $n->data['url']);
        // The editor didn't opt in.
        $this->assertSame(0, Notification::forUser($this->editor)->count());

        $this->heartbeat($device);
        $this->assertSame('device_online', Notification::forUser($this->leader)->latest()->first()->type);
        $this->assertSame(2, Notification::forUser($this->leader)->count());

        // A normal heartbeat afterwards says nothing more.
        $this->heartbeat($device);
        $this->assertSame(2, Notification::forUser($this->leader)->count());
    }

    public function test_long_silent_devices_are_marked_without_a_notice(): void
    {
        $this->optInToStatus($this->leader);
        $device = $this->device(['last_seen_at' => now()->subDays(3)]);

        $this->artisan('notifications:device-status');

        $this->assertSame(0, Notification::count());
        $this->assertNotNull($device->fresh()->offline_notified_at);
    }
}
