<?php

namespace App\Support\SlideAnnouncer;

use App\Models\SlideAnnouncer;

/**
 * A product's data riding on the core device heartbeat — the server half of
 * the "Extensions" section of slideannouncer/docs/DEVICE_CONTRACT.md. The
 * core heartbeat controller knows nothing about any extension's fields; it
 * only asks each registered one (see HeartbeatExtensions) to validate,
 * absorb and answer its own keys.
 */
interface HeartbeatExtension
{
    /** Validation rules for the extension's request keys. */
    public function rules(): array;

    /**
     * Applies the validated request data to the device. Called after the
     * core snapshot is saved; the controller saves the device afterwards,
     * so implementations only set attributes.
     */
    public function absorb(SlideAnnouncer $device, array $data): void;

    /** Flat keys merged into the heartbeat response body. */
    public function respond(SlideAnnouncer $device): array;
}
