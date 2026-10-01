<?php

namespace App\Support;

use App\Models\SlideAnnouncer;

/**
 * Two-way sync of a Slide Announcer's LAN Video Receiver settings (mode,
 * passphrases, RIST multicast group/port, encryption), which can be edited
 * both on the device (Settings > LAN Video Receiver) and on the Slide
 * Announcer web page. Device side: slideannouncer/local-app/backend/
 * srt_sink.py (SERVER_EDITABLE_FIELDS, apply_server_config(), report()).
 *
 * - Every web edit that changes something bumps `srt_sink_config_revision`.
 * - Heartbeat and slide-sync responses carry push(): the editable fields
 *   plus that revision. The device applies it only if it's newer than the
 *   last revision it applied.
 * - Every heartbeat reports the device's current settings plus the last
 *   revision it applied (absorbReport()). A report at the server's latest
 *   revision is taken as current, which is how edits made on the device
 *   reach this server. A report from behind (a web edit not applied yet)
 *   only updates the device-only status fields, so it can't undo the
 *   pending edit.
 */
class SlideAnnouncerVideoReceiver
{
    public const MODES = ['srt', 'rist_unicast', 'rist_multicast'];

    /** Settable from either side — the fields push() sends down. */
    public const EDITABLE = [
        'mode', 'passphrase', 'multicast_group', 'multicast_port', 'multicast_passphrase', 'rist_encryption_bits',
    ];

    /** Device-reported only (local on/off switch, tuning, capabilities). */
    public const REPORTED = [
        'local_enabled', 'srt_latency_ms', 'rist_buffer_ms', 'rist_supported', 'rist_port', 'srt_port', 'apply_error',
    ];

    /**
     * Same rules as the device's srt_sink.update_settings(), so a web edit
     * can't push something the device would reject. Keys are prefixed with
     * `srt_sink_config.` for use directly in a request's validate().
     */
    public static function rules(): array
    {
        // libsrt's passphrase rule (10-79 chars), printable ASCII, no spaces.
        $passphrase = ['regex:/^[\x21-\x7E]{10,79}$/'];
        $multicast = 'required_if:srt_sink_config.mode,rist_multicast';

        return [
            'srt_sink_config' => 'sometimes|array',
            'srt_sink_config.mode' => 'required_with:srt_sink_config|in:'.implode(',', self::MODES),
            'srt_sink_config.passphrase' => ['nullable', 'string', ...$passphrase],
            'srt_sink_config.multicast_group' => [$multicast, 'nullable', 'ipv4', function ($attribute, $value, $fail) {
                if ($value !== null && ! self::isMediaMulticast($value)) {
                    $fail('Multicast IP must be in 224.0.1.0–239.255.255.255 (e.g. 239.1.2.3).');
                }
            }],
            'srt_sink_config.multicast_port' => [$multicast, 'nullable', 'integer', 'between:1024,65534', function ($attribute, $value, $fail) {
                if ($value !== null && (int) $value % 2 !== 0) {
                    $fail('Port must be even (RIST also uses the next port up).');
                }
            }],
            'srt_sink_config.multicast_passphrase' => [$multicast, 'nullable', 'string', ...$passphrase],
            'srt_sink_config.rist_encryption_bits' => 'nullable|in:128,256',
        ];
    }

    /** 224.0.1.0-239.255.255.255 — multicast, minus the 224.0.0.x block routing protocols use. */
    public static function isMediaMulticast(string $ip): bool
    {
        $long = ip2long($ip);

        return $long !== false && $long >= ip2long('224.0.1.0') && $long <= ip2long('239.255.255.255');
    }

    /**
     * Applies a web edit; bumps the revision only if an editable value
     * actually changed, so saving the page for an unrelated field doesn't
     * push anything to the device.
     */
    public static function applyWebEdit(SlideAnnouncer $device, array $edit): void
    {
        $current = $device->srt_sink_config ?? [];
        $next = $current;
        foreach (self::EDITABLE as $key) {
            if (array_key_exists($key, $edit)) {
                $next[$key] = $key === 'multicast_port' || $key === 'rist_encryption_bits'
                    ? ($edit[$key] === null ? null : (int) $edit[$key])
                    : $edit[$key];
            }
        }

        // '' (as the device reports an unset field) and null (as a blank
        // form field arrives) both mean "not set".
        $norm = fn ($value) => $value === '' ? null : $value;
        $changed = collect(self::EDITABLE)->contains(fn ($key) => $norm($next[$key] ?? null) !== $norm($current[$key] ?? null));
        if (! $changed) {
            return;
        }

        $device->srt_sink_config = $next;
        $device->srt_sink_config_revision = $device->srt_sink_config_revision + 1;
        if (! empty($next['passphrase'])) {
            $device->srt_sink_passphrase = $next['passphrase'];
        }
    }

    /** Folds a heartbeat's `srt_sink_config` report into the device row (not saved). */
    public static function absorbReport(SlideAnnouncer $device, array $report): void
    {
        $applied = (int) ($report['revision'] ?? 0);
        $device->srt_sink_config_applied_revision = $applied;
        // A device ahead of this server (e.g. after a server-side restore)
        // — catch up, or the next web edit's revision would be ignored.
        if ($applied > $device->srt_sink_config_revision) {
            $device->srt_sink_config_revision = $applied;
        }

        $reported = array_intersect_key($report, array_flip([...self::EDITABLE, ...self::REPORTED]));
        if ($applied >= $device->srt_sink_config_revision) {
            $device->srt_sink_config = $reported;
            if (! empty($reported['passphrase'])) {
                $device->srt_sink_passphrase = $reported['passphrase'];
            }
        } else {
            $pending = array_intersect_key($device->srt_sink_config ?? [], array_flip(self::EDITABLE));
            $device->srt_sink_config = [...$reported, ...$pending];
        }
    }

    /** What the heartbeat/sync responses send down — null until there's been a web edit. */
    public static function push(SlideAnnouncer $device): ?array
    {
        if ($device->srt_sink_config_revision === 0) {
            return null;
        }

        return [
            'revision' => $device->srt_sink_config_revision,
            ...array_intersect_key($device->srt_sink_config ?? [], array_flip(self::EDITABLE)),
        ];
    }

    public static function pending(SlideAnnouncer $device): bool
    {
        return $device->srt_sink_config_applied_revision < $device->srt_sink_config_revision;
    }
}
