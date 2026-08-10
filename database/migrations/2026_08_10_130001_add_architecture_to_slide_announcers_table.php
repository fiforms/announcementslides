<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Device-reported architecture (e.g. 'arm64'), updated via heartbeat —
     * needed so the heartbeat controller can pick a release tagged for
     * this device's channel AND matching hardware, not just channel.
     */
    public function up(): void
    {
        Schema::table('slide_announcers', function (Blueprint $table) {
            $table->string('architecture')->nullable()->after('os_version');
        });
    }

    public function down(): void
    {
        Schema::table('slide_announcers', function (Blueprint $table) {
            $table->dropColumn('architecture');
        });
    }
};
