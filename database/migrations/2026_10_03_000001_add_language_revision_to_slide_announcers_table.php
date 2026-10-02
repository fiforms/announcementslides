<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Bumped on every language change, from either side (web edit or a
    // device-originated change on a heartbeat). The device echoes back the
    // revision it last saw with any change it makes, which is how a stale
    // device edit is told apart from a fresh one — see
    // SlideAnnouncerHeartbeatController.
    public function up(): void
    {
        Schema::table('slide_announcers', function (Blueprint $table) {
            $table->unsignedInteger('language_revision')->default(0)->after('language_id');
        });
    }

    public function down(): void
    {
        Schema::table('slide_announcers', function (Blueprint $table) {
            $table->dropColumn('language_revision');
        });
    }
};
