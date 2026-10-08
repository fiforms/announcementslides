<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\SlideAnnouncer;
use App\Models\SlideAnnouncerRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the heartbeat offers a device, with pair versions (<platform>_<product>):
 * a product-only app release is an update, and a hotfix gates on the device's
 * exact OS version, plain or paired.
 */
class SlideAnnouncerPairVersionTest extends TestCase
{
    use RefreshDatabase;

    private SlideAnnouncer $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = SlideAnnouncer::create(['entity_id' => Entity::create(['name' => 'Church'])->id, 'name' => 'Lobby']);
    }

    private function release(string $kind, string $version, string $type = 'full', ?string $base = null): SlideAnnouncerRelease
    {
        $release = SlideAnnouncerRelease::create([
            'kind' => $kind, 'version' => $version, 'architecture' => 'arm64', 'release_type' => $type,
            'required_base_version' => $base, 'disk_path' => "slide-announcer/releases/{$kind}/{$version}.bin", 'sha256' => str_repeat('a', 64),
        ]);
        $release->tagChannel('stable');

        return $release;
    }

    private function heartbeat(string $appVersion, string $osVersion)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->device->createToken('d')->plainTextToken)
            ->postJson('/api/slide-announcers/heartbeat', ['app_version' => $appVersion, 'os_version' => $osVersion, 'architecture' => 'arm64'])
            ->assertOk();
    }

    public function test_a_product_only_app_release_is_an_update_and_the_same_pair_is_not(): void
    {
        $this->release('app', '0.4.0_0.1.1');

        // A device on the legacy (pre-pair) app, and on the pair below it.
        $this->assertTrue($this->heartbeat('0.4.0-8066cfc-slideannouncer-b0.1.0-f0.1.0-8f51f4b', '0.4.1_0.1.0')->json('app_update_available'));
        $this->assertTrue($this->heartbeat('0.4.0_0.1.0-8066cfc-slideannouncer-e4b6392', '0.4.1_0.1.0')->json('app_update_available'));
        // Already on it (a rebuilt hash doesn't count), or ahead of it.
        $this->assertFalse($this->heartbeat('0.4.0_0.1.1-aaaa-slideannouncer-bbbb', '0.4.1_0.1.0')->json('app_update_available'));
        $this->assertFalse($this->heartbeat('0.4.1_0.1.0-aaaa-slideannouncer-bbbb', '0.4.1_0.1.0')->json('app_update_available'));
    }

    public function test_a_platform_only_app_release_is_an_update(): void
    {
        $this->release('app', '0.4.1_0.1.1');

        $this->assertTrue($this->heartbeat('0.4.0_0.1.1-aaaa-slideannouncer-bbbb', '0.4.1_0.1.0')->json('app_update_available'));
    }

    public function test_hotfixes_match_the_devices_exact_os_version(): void
    {
        $this->release('os', '0.4.1_0.1.0', 'hotfix', '0.4.0');
        $this->release('os', '0.4.1_0.1.1', 'hotfix', '0.4.1_0.1.0');
        $this->release('os', '0.4.2_0.1.0', 'hotfix', '0.4.1_0.1.0');

        // Legacy device -> the first pair-versioned hotfix.
        $first = $this->heartbeat('0.4.0', '0.4.0')->json();
        $this->assertTrue($first['os_update_available']);
        $this->assertSame('0.4.1_0.1.0', $first['latest_os_version']);
        $this->assertSame('hotfix', $first['os_release_type']);

        // A device at 0.4.1_0.1.0 has two hotfixes with the same base tagged on
        // one channel; tagging evicts the older one, so exactly one is offered.
        $second = $this->heartbeat('0.4.0', '0.4.1_0.1.0')->json();
        $this->assertTrue($second['os_update_available']);
        $this->assertSame('0.4.2_0.1.0', $second['latest_os_version']);

        // A device on neither base is offered nothing.
        $this->assertFalse($this->heartbeat('0.4.0', '0.5.0_0.1.0')->json('os_update_available'));
    }
}
