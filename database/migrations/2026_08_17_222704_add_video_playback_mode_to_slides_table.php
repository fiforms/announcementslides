<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->enum('video_playback_mode', ['play_through', 'hold_last_frame', 'loop'])
                ->default('hold_last_frame')
                ->after('link');
        });
    }

    public function down(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->dropColumn('video_playback_mode');
        });
    }
};
