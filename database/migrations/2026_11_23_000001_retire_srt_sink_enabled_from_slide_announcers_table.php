<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The LAN Video Receiver's on/off now syncs both ways like the rest of
     * its settings (`enabled` inside `srt_sink_config`; see
     * App\Support\SlideAnnouncerVideoReceiver), replacing the one-way
     * `srt_sink_enabled` force-disable. Announcers an admin had force-disabled
     * get a pending web edit turning the receiver off, so that intent
     * survives; everything else adopts whatever the device reports.
     */
    public function up(): void
    {
        DB::table('slide_announcers')->where('srt_sink_enabled', false)->orderBy('id')->each(function ($row) {
            $config = json_decode($row->srt_sink_config ?? '[]', true) ?: [];
            $config['enabled'] = false;
            DB::table('slide_announcers')->where('id', $row->id)->update([
                'srt_sink_config' => json_encode($config),
                'srt_sink_config_revision' => $row->srt_sink_config_revision + 1,
            ]);
        });

        Schema::table('slide_announcers', function (Blueprint $table) {
            $table->dropColumn('srt_sink_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('slide_announcers', function (Blueprint $table) {
            $table->boolean('srt_sink_enabled')->default(true)->after('auto_update_enabled');
        });
    }
};
