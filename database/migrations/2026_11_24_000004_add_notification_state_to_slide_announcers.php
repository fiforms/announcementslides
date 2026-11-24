<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Device notification state (see App\Notifications\DeviceNotifications):
 *  - temp_alert_at: when the last over-temperature notice went out; cleared
 *    once the device has cooled down, which re-arms the alert.
 *  - offline_notified_at: set when a device is noticed offline; cleared by
 *    its next heartbeat, which sends the "back online" notice.
 * Devices already offline are marked, so the first sweep doesn't announce them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slide_announcers', function (Blueprint $table) {
            $table->timestamp('temp_alert_at')->nullable();
            $table->timestamp('offline_notified_at')->nullable();
        });

        $minutes = (int) config('slide_announcer.heartbeat_interval_minutes', 5)
            * (int) config('slide_announcer.offline_after_missed_heartbeats', 3);

        DB::table('slide_announcers')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', now()->subMinutes($minutes))
            ->update(['offline_notified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('slide_announcers', function (Blueprint $table) {
            $table->dropColumn(['temp_alert_at', 'offline_notified_at']);
        });
    }
};
