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
            'enabled' => true,
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
        $this->heartbeat($device, $this->report(['srt_port' => 7010]))
            ->assertJsonPath('srt_sink_config.mode', 'rist_multicast');
        $device->refresh();
        $this->assertSame('rist_multicast', $device->srt_sink_config['mode']);
        $this->assertSame(7010, $device->srt_sink_config['srt_port']);
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

    public function test_rist_caller_edit_is_validated_and_pushed(): void
    {
        $device = $this->makeDevice();

        $this->webEdit($device, [
            'mode' => 'rist_caller',
            'sender_host' => 'bad host?',
            'sender_port' => 5001,
            'sender_passphrase' => 'short',
        ])->assertSessionHasErrors([
            'srt_sink_config.sender_host',
            'srt_sink_config.sender_port',
            'srt_sink_config.sender_passphrase',
        ]);

        // Host, port and passphrase are all required once the mode is chosen.
        $this->webEdit($device, ['mode' => 'rist_caller'])->assertSessionHasErrors([
            'srt_sink_config.sender_host',
            'srt_sink_config.sender_passphrase',
        ]);

        $this->webEdit($device, [
            'mode' => 'rist_caller',
            'sender_host' => 'obs-pc.local',
            'sender_port' => 5000,
            'sender_passphrase' => 'SenderPass01',
        ])->assertSessionHasNoErrors();

        $this->asDevice($device)->getJson('/api/slide-announcers/shows')
            ->assertJsonPath('srt_sink_config.mode', 'rist_caller')
            ->assertJsonPath('srt_sink_config.sender_host', 'obs-pc.local')
            ->assertJsonPath('srt_sink_config.sender_port', 5000);
    }

    public function test_web_enable_is_pushed_down_and_a_device_toggle_comes_back_up(): void
    {
        $device = $this->makeDevice();
        $this->heartbeat($device, $this->report(['enabled' => false, 'local_enabled' => false]))->assertOk();

        $this->webEdit($device, ['mode' => 'srt', 'enabled' => true, 'passphrase' => 'DevicePass01'])->assertSessionHasNoErrors();
        $this->heartbeat($device, $this->report(['enabled' => false, 'local_enabled' => false]))
            ->assertJsonPath('srt_sink_config.enabled', true)
            ->assertJsonPath('srt_sink_config.revision', 1);

        // Device applies it, then the operator switches it off locally.
        $this->heartbeat($device, $this->report(['revision' => 1]))->assertOk();
        $this->assertTrue($device->fresh()->srt_sink_config['enabled']);
        $this->heartbeat($device, $this->report(['revision' => 1, 'enabled' => false, 'local_enabled' => false]))->assertOk();
        $this->assertFalse($device->fresh()->srt_sink_config['enabled']);
    }

    public function test_buffer_edits_sync_both_ways_and_are_validated(): void
    {
        $device = $this->makeDevice();
        $this->heartbeat($device, $this->report())->assertOk();

        $this->webEdit($device, ['mode' => 'srt', 'srt_latency_ms' => 10])->assertSessionHasErrors('srt_sink_config.srt_latency_ms');

        $this->webEdit($device, ['mode' => 'srt', 'srt_latency_ms' => 300, 'rist_buffer_ms' => 750])->assertSessionHasNoErrors();
        $this->heartbeat($device, $this->report())
            ->assertJsonPath('srt_sink_config.srt_latency_ms', 300)
            ->assertJsonPath('srt_sink_config.rist_buffer_ms', 750);

        // Device applies, then the operator changes the buffer locally.
        $this->heartbeat($device, $this->report(['revision' => 1, 'srt_latency_ms' => 450, 'rist_buffer_ms' => 750]))->assertOk();
        $this->assertSame(450, $device->fresh()->srt_sink_config['srt_latency_ms']);
    }
}
