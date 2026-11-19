<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Play the background-music playlist in random order (a fresh order each pass).
        Schema::table('shows', function (Blueprint $table) {
            $table->boolean('frame_audio_shuffle')->default(false)->after('frame_audio_volume');
        });
    }

    public function down(): void
    {
        Schema::table('shows', fn (Blueprint $table) => $table->dropColumn('frame_audio_shuffle'));
    }
};
