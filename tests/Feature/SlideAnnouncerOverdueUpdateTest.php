<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\SlideAnnouncer;
use App\Models\SlideAnnouncerRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The heartbeat tells a device how long its offered update has been
 * available, so one that is rarely powered on can tell it is overdue.
 */
class SlideAnnouncerOverdueUpdateTest extends TestCase
{
    use RefreshDatabase;

    private SlideAnnouncer $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = SlideAnnouncer::create(['entity_id' => Entity::create(['name' => 'Church'])->id, 'name' => 'Lobby']);
    }

    private function release(string $kind, string $version, string $taggedHoursAgo): SlideAnnouncerRelease
    {
        $release = SlideAnnouncerRelease::create([
            'kind' => $kind, 'version' => $version, 'architecture' => 'arm64', 'release_type' => 'full',
            'disk_path' => "slide-announcer/releases/{$kind}/{$version}.bin", 'sha256' => str_repeat('a', 64),
        ]);
        $release->tagChannel('stable');
        $release->channels()->update(['created_at' => now()->subHours($taggedHoursAgo)]);

        return $release;
    }

    private function heartbeat()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->device->createToken('d')->plainTextToken)
            ->postJson('/api/slide-announcers/heartbeat', ['app_version' => '0.4.0_0.1.0', 'os_version' => '0.4.0_0.1.0', 'architecture' => 'arm64'])
            ->assertOk();
    }

    public function test_the_age_counts_from_when_the_release_was_tagged(): void
    {
        $this->release('app', '0.4.0_0.1.1', 80);
        $this->release('os', '0.4.1_0.1.0', 5);

        $response = $this->heartbeat()->json();

        $this->assertSame(80, $response['app_update_age_hours']);
        $this->assertSame(5, $response['os_update_age_hours']);
        $this->assertSame(72, $response['update_overdue_after_hours']);
    }

    public function test_the_threshold_is_configurable(): void
    {
        config(['slide_announcer.update_overdue_after_hours' => 24]);

        $this->assertSame(24, $this->heartbeat()->json('update_overdue_after_hours'));
    }

    public function test_there_is_no_age_when_nothing_is_offered(): void
    {
        $response = $this->heartbeat()->json();

        $this->assertNull($response['app_update_age_hours']);
        $this->assertNull($response['os_update_age_hours']);
    }

    public function test_retagging_restarts_the_clock(): void
    {
        $release = $this->release('app', '0.4.0_0.1.1', 100);
        $release->untagChannel('stable');
        $release->tagChannel('stable');

        $this->assertSame(0, $this->heartbeat()->json('app_update_age_hours'));
    }
}
