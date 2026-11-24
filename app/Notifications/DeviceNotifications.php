<?php

namespace App\Notifications;

use App\Models\SlideAnnouncer;
use App\Services\Notifier;
use App\Support\NotificationSettings;

/**
 * Notices about a church's Slide Announcer devices, sent to that church's
 * leaders and editors (the people who can manage its devices):
 *
 *  - device_temperature: a heartbeat reported a CPU temperature at or over
 *    the site's limit (Admin → Notifications, default 70 °C). Sent once,
 *    then held until the device has cooled COOL_DOWN_MARGIN degrees below
 *    the limit and REARM_AFTER_MINUTES have passed, so a device hovering
 *    around the limit doesn't send a notice every heartbeat.
 *  - device_status: went offline (missed the configured number of
 *    heartbeats; see the notifications:device-status sweep) and came back.
 *    Opt-in: off by default on everyone's preferences page.
 */
class DeviceNotifications
{
    public const COOL_DOWN_MARGIN = 5;

    public const REARM_AFTER_MINUTES = 60;

    /** Called for every heartbeat, after the device row is updated. */
    public static function heartbeat(SlideAnnouncer $device, ?float $cpuTempC): void
    {
        self::backOnline($device);

        if ($cpuTempC !== null) {
            self::checkTemperature($device, $cpuTempC);
        }
    }

    public static function checkTemperature(SlideAnnouncer $device, float $cpuTempC): void
    {
        $limit = NotificationSettings::get('device_temp_threshold_c');

        if ($cpuTempC >= $limit) {
            // Atomic claim: only one notice per hot spell.
            $claimed = SlideAnnouncer::whereKey($device->id)->whereNull('temp_alert_at')
                ->update(['temp_alert_at' => now()]);

            if ($claimed) {
                self::notify($device, 'device_temperature', 'device_hot', [
                    'temp'  => round($cpuTempC),
                    'limit' => $limit,
                ]);
            }

            return;
        }

        if ($cpuTempC < $limit - self::COOL_DOWN_MARGIN) {
            SlideAnnouncer::whereKey($device->id)->whereNotNull('temp_alert_at')
                ->where('temp_alert_at', '<', now()->subMinutes(self::REARM_AFTER_MINUTES))
                ->update(['temp_alert_at' => null]);
        }
    }

    /** A device that was reported offline heartbeated again. */
    public static function backOnline(SlideAnnouncer $device): void
    {
        $cleared = SlideAnnouncer::whereKey($device->id)->whereNotNull('offline_notified_at')
            ->update(['offline_notified_at' => null]);

        if ($cleared) {
            self::notify($device, 'device_status', 'device_online');
        }
    }

    public static function wentOffline(SlideAnnouncer $device): void
    {
        self::notify($device, 'device_status', 'device_offline', [
            'missed' => (int) config('slide_announcer.offline_after_missed_heartbeats', 3),
        ]);
    }

    /** Minutes without a heartbeat after which a device counts as offline. */
    public static function offlineAfterMinutes(): int
    {
        return (int) config('slide_announcer.heartbeat_interval_minutes', 5)
            * (int) config('slide_announcer.offline_after_missed_heartbeats', 3);
    }

    private static function notify(SlideAnnouncer $device, string $category, string $type, array $params = []): void
    {
        $device->loadMissing('entity');
        if (! $device->entity || $device->entity->deactivated) {
            return;
        }

        $recipients = array_keys(SlideAvailabilityNotifications::managersByEntity([$device->entity_id]));

        Notifier::send($category, $type, $recipients, [
            'params' => [
                'device' => $device->name ?: ($device->hostname ?: 'Slide Announcer'),
                'entity' => $device->entity->name,
            ] + $params,
            'url' => route('slide-announcers.show', [
                'slideAnnouncer' => $device->id,
                'entity_id' => $device->entity_id,
            ], absolute: false),
        ]);
    }
}
