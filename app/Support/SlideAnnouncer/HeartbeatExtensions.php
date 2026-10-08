<?php

namespace App\Support\SlideAnnouncer;

/** Registry of the extensions the heartbeat controller consults. */
class HeartbeatExtensions
{
    /** @return list<HeartbeatExtension> */
    public static function all(): array
    {
        return [
            new VideoReceiverHeartbeatExtension,
        ];
    }

    public static function rules(): array
    {
        return array_merge(...array_map(fn (HeartbeatExtension $e) => $e->rules(), static::all()));
    }

    public static function absorb($device, array $data): void
    {
        foreach (static::all() as $extension) {
            $extension->absorb($device, $data);
        }
    }

    public static function respond($device): array
    {
        return array_merge(...array_map(fn (HeartbeatExtension $e) => $e->respond($device), static::all()));
    }
}
