<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\SlideAnnouncer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two-way LAN Video Receiver settings sync — see
 * App\Support\SlideAnnouncerVideoReceiver.
 */
class SlideAnnouncerVideoReceiverSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeDevice(): SlideAnnouncer
    {
        $entity = Entity::create(['name' => 'Test Church']);

        return SlideAnnouncer::create(['entity_id' => $entity->id, 'name' => 'Lobby']);
    }

    private function report(array $overrides = []): array
    {
        return [
            'mode' => 'srt',
            'passphrase' => 'DevicePass01',
            'multicast_group' => '',
            'multicast_port' => 5000,
            'multicast_passphrase' => '',
            'rist_encryption_bits' => 128,
            'local_enabled' => true,
            'srt_latency_ms' => 120,
            'rist_buffer_ms' => 500,
            'rist_supported' => true,
            'rist_port' => 5000,
            'srt_port' => 7002,
            'apply_error' => null,
            'revision' => 0,
            ...$overrides,
        ];
    }

    // A real device token, same as production — SlideAnnouncer isn't an
    // Authenticatable, so Sanctum::actingAs() can't stand in for it.
    private function asDevice(SlideAnnouncer $device): static
    {
        // The test client reuses one app instance, whose guard would keep
        // serving the device as first loaded — each real request reloads it.
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->createToken('device')->plainTextToken);
    }

    private function heartbeat(SlideAnnouncer $device, array $report)
    {
        return $this->asDevice($device)->postJson('/api/slide-announcers/heartbeat', ['srt_sink_config' => $report]);
    }

    private function webEdit(SlideAnnouncer $device, array $config)
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return $this->actingAs($admin)->patch(
            route('slide-announcers.update', ['slideAnnouncer' => $device->id, 'entity_id' => $device->entity_id]),
            ['srt_sink_config' => $config],
        );
    }

    public function test_device_report_is_stored_and_nothing_is_pushed_before_any_web_edit(): void
    {
        $device = $this->makeDevice();

        $this->heartbeat($device, $this->report())
            ->assertOk()
            ->assertJsonPath('srt_sink_config', null);

        $device->refresh();
        $this->assertSame('DevicePass01', $device->srt_sink_config['passphrase']);
        $this->assertSame('DevicePass01', $device->srt_sink_passphrase);
        $this->assertFalse($device->srt_sink_config_applied_revision < $device->srt_sink_config_revision);
    }

    public function test_web_edit_is_pushed_and_survives_a_stale_report_until_applied(): void
    {
        $device = $this->makeDevice();
        $this->heartbeat($device, $this->report());

        $this->webEdit($device, [
            'mode' => 'rist_multicast',
            'passphrase' => 'DevicePass01',
            'multicast_group' => '239.1.2.3',
            'multicast_port' => 6000,
            'multicast_passphrase' => 'SenderPass99',
            'rist_encryption_bits' => 256,
        ])->assertSessionHasNoErrors();

        $device->refresh();
        $this->assertSame(1, $device->srt_sink_config_revision);

        // Pushed in both the heartbeat and the slide-sync responses.
        $this->asDevice($device)->getJson('/api/slide-announcers/shows')
            ->assertJsonPath('srt_sink_config.revision', 1)
            ->assertJsonPath('srt_sink_config.multicast_group', '239.1.2.3');

        // A report from before the device applied it must not undo the edit,
        // but device-only status still updates.
        $this->heartbeat($device, $this->report(['srt_latency_ms' => 240]))
            ->assertJsonPath('srt_sink_config.mode', 'rist_multicast');
        $device->refresh();
        $this->assertSame('rist_multicast', $device->srt_sink_config['mode']);
        $this->assertSame(240, $device->srt_sink_config['srt_latency_ms']);
        $this->assertSame(0, $device->srt_sink_config_applied_revision);

        // Device applied it and reports at revision 1 — now current.
        $this->heartbeat($device, $this->report([
            'mode' => 'rist_multicast', 'multicast_group' => '239.1.2.3', 'multicast_port' => 6000,
            'multicast_passphrase' => 'SenderPass99', 'rist_encryption_bits' => 256, 'revision' => 1,
        ]));
        $device->refresh();
        $this->assertSame(1, $device->srt_sink_config_applied_revision);
    }

    public function test_local_device_edit_reaches_the_server_when_reported_at_current_revision(): void
    {
        $device = $this->makeDevice();
        $device->update(['srt_sink_config_revision' => 3, 'srt_sink_config_applied_revision' => 3]);

        $this->heartbeat($device, $this->report(['passphrase' => 'EditedOnTv22', 'revision' => 3]));

        $device->refresh();
        $this->assertSame('EditedOnTv22', $device->srt_sink_config['passphrase']);
        $this->assertSame('EditedOnTv22', $device->srt_sink_passphrase);
    }

    public function test_device_ahead_of_server_pulls_the_server_revision_forward(): void
    {
        $device = $this->makeDevice();

        $this->heartbeat($device, $this->report(['revision' => 7]));

        $this->assertSame(7, $device->refresh()->srt_sink_config_revision);
    }

    public function test_saving_without_receiver_changes_does_not_push(): void
    {
        $device = $this->makeDevice();
        $this->heartbeat($device, $this->report());

        $this->webEdit($device, [
            'mode' => 'srt', 'passphrase' => 'DevicePass01', 'multicast_group' => '',
            'multicast_port' => 5000, 'multicast_passphrase' => '', 'rist_encryption_bits' => 128,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $device->refresh()->srt_sink_config_revision);
    }

    public function test_web_edit_validation_matches_the_device_rules(): void
    {
        $device = $this->makeDevice();

        $this->webEdit($device, [
            'mode' => 'rist_multicast',
            'multicast_group' => '10.0.0.1',
            'multicast_port' => 5001,
            'multicast_passphrase' => 'short',
        ])->assertSessionHasErrors([
            'srt_sink_config.multicast_group',
            'srt_sink_config.multicast_port',
            'srt_sink_config.multicast_passphrase',
        ]);

        $this->webEdit($device, ['mode' => 'srt', 'passphrase' => 'has a space'])
            ->assertSessionHasErrors(['srt_sink_config.passphrase']);

        $this->assertSame(0, $device->refresh()->srt_sink_config_revision);
    }
}
