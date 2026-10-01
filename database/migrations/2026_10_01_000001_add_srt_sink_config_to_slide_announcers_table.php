<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LAN Video Receiver settings (SRT / RIST unicast / RIST multicast —
     * see slideannouncer/local-app/backend/srt_sink.py), now editable from
     * both the device and this server's Slide Announcer page.
     * `srt_sink_config` holds the device's last-reported settings, or a
     * pending web edit. `srt_sink_config_revision` is bumped on every web
     * edit and pushed down to the device; `srt_sink_config_applied_revision`
     * is the revision the device last reported having applied — while it's
     * behind, the page shows the edit as pending. See
     * App\Support\SlideAnnouncerVideoReceiver for the full sync rules.
     */
    public function up(): void
    {
        Schema::table('slide_announcers', function (Blueprint $table) {
            $table->json('srt_sink_config')->nullable()->after('srt_sink_passphrase');
            $table->unsignedInteger('srt_sink_config_revision')->default(0)->after('srt_sink_config');
            $table->unsignedInteger('srt_sink_config_applied_revision')->default(0)->after('srt_sink_config_revision');
        });
    }

    public function down(): void
    {
        Schema::table('slide_announcers', function (Blueprint $table) {
            $table->dropColumn(['srt_sink_config', 'srt_sink_config_revision', 'srt_sink_config_applied_revision']);
        });
    }
};
