<?php

namespace App\Console\Commands;

use App\Models\SlideAnnouncer;
use App\Notifications\DeviceNotifications;
use Illuminate\Console\Command;

/**
 * Finds Slide Announcers that stopped heartbeating (missed
 * slide_announcer.offline_after_missed_heartbeats in a row) and sends their
 * church's managers a "went offline" status notice. `offline_notified_at`
 * marks the device so it's reported once; its next heartbeat clears the
 * mark and sends "back online" (DeviceNotifications::backOnline).
 *
 * Devices silent for more than LOOKBACK_HOURS (the scheduler was down, or
 * a device long since unplugged) are marked without a notice.
 */
class NotificationsDeviceStatus extends Command
{
    protected $signature = 'notifications:device-status';

    protected $description = 'Notify church managers about Slide Announcers that went offline';

    private const LOOKBACK_HOURS = 24;

    public function handle(): int
    {
        $cutoff = now()->subMinutes(DeviceNotifications::offlineAfterMinutes());
        $tooOld = now()->subHours(self::LOOKBACK_HOURS);
        $sent = 0;

        SlideAnnouncer::query()
            ->whereNull('revoked_at')
            ->whereNull('offline_notified_at')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $cutoff)
            ->with('entity')
            ->chunkById(200, function ($devices) use ($cutoff, $tooOld, &$sent) {
                foreach ($devices as $device) {
                    // Claim it first; a heartbeat that slipped in meanwhile wins.
                    $claimed = SlideAnnouncer::whereKey($device->id)
                        ->whereNull('offline_notified_at')
                        ->where('last_seen_at', '<', $cutoff)
                        ->update(['offline_notified_at' => now()]);

                    if ($claimed && $device->last_seen_at->gte($tooOld)) {
                        DeviceNotifications::wentOffline($device);
                        $sent++;
                    }
                }
            });

        $this->info("Reported {$sent} offline device(s).");

        return self::SUCCESS;
    }
}
