<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * On-demand SRT video-sink (see slideannouncer/system/scripts/
     * srt-sink-monitor.py) — `srt_sink_enabled` is an admin force-disable
     * switch, same pattern and default as `auto_update_enabled`: the
     * device's own Settings toggle is device-side state
     * (/data/status/srt-sink.json), this column is only the fleet-wide
     * kill switch, folded into the heartbeat response so an explicit
     * false always overrides the device's local toggle.
     * `srt_sink_passphrase` is reported *by* the device (generated
     * on-device, never set here) purely so an admin can read it off the
     * fleet dashboard to configure their SRT sender. `hostname` is also
     * device-reported (its own mDNS name, e.g. slideannouncer-123456 —
     * see slideannouncer/provisioning/firstboot.py's set_hostname()) so
     * the fleet dashboard can show the same full "Connect With" srt://
     * URL the device's own Settings > Video Receiver screen does, without
     * this server needing to duplicate that hostname-derivation logic.
     */
    public function up(): void
    {
        Schema::table('slide_announcers', function (Blueprint $table) {
            $table->boolean('srt_sink_enabled')->default(true)->after('auto_update_enabled');
            $table->string('srt_sink_passphrase')->nullable()->after('srt_sink_enabled');
            $table->string('hostname')->nullable()->after('srt_sink_passphrase');
        });
    }

    public function down(): void
    {
        Schema::table('slide_announcers', function (Blueprint $table) {
            $table->dropColumn(['srt_sink_enabled', 'srt_sink_passphrase', 'hostname']);
        });
    }
};
