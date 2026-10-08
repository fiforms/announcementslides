<?php

namespace App\Support\SlideAnnouncer;

use App\Models\SlideAnnouncer;
use App\Support\SlideAnnouncerVideoReceiver;

/**
 * The signage product's LAN video receiver (`srt_sink_*` keys) on the
 * heartbeat. Its settings logic lives in SlideAnnouncerVideoReceiver; this
 * only adapts it to the extension interface.
 */
class VideoReceiverHeartbeatExtension implements HeartbeatExtension
{
    public function rules(): array
    {
        return [
            // Device-generated (never operator-typed — see the signage
            // product's backend/srt_sink.py), reported so an admin can read
            // it off the fleet dashboard to configure their SRT sender. Only
            // present once the device has enabled the receiver locally at
            // least once; absent otherwise, in which case the stored value
            // is left alone.
            'srt_sink_passphrase' => 'nullable|string|max:255',
            // The device's full LAN Video Receiver settings plus the last
            // web-edit revision it applied — absent from older app versions.
            'srt_sink_config' => 'nullable|array',
        ];
    }

    public function absorb(SlideAnnouncer $device, array $data): void
    {
        if (isset($data['srt_sink_passphrase'])) {
            $device->srt_sink_passphrase = $data['srt_sink_passphrase'];
        }

        if (isset($data['srt_sink_config'])) {
            SlideAnnouncerVideoReceiver::absorbReport($device, $data['srt_sink_config']);
        }
    }

    public function respond(SlideAnnouncer $device): array
    {
        return [
            // Fleet-wide force-disable (EntitySlideAnnouncerController::update)
            // — an explicit false always overrides the device's own local
            // Settings toggle.
            'srt_sink_enabled' => $device->srt_sink_enabled,
            // Receiver settings edited on the Slide Announcer page, with the
            // revision the device uses to tell a new edit from one it has
            // already applied. Also sent with every slide sync.
            'srt_sink_config' => SlideAnnouncerVideoReceiver::push($device),
        ];
    }
}
