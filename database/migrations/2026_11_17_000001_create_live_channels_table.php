<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live channels: a global, admin-curated catalog of live streams (Hope
     * Channel, AFTV, …) a show can use as its frame background. Stored as
     * provider + parsed, validated ids (see App\Support\LiveChannelSource),
     * never as a pasted URL; the embed URL is built at read time. Shows refer
     * to a channel by id, so fixing a channel once fixes every show using it.
     * Exclusive with a `show-base` media row and with `frame_youtube`.
     */
    public function up(): void
    {
        Schema::create('live_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider', 32);
            $table->json('source');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('shows', function (Blueprint $table) {
            $table->foreignId('frame_channel_id')->nullable()->after('frame_youtube')
                ->constrained('live_channels')->nullOnDelete();
            $table->boolean('frame_channel_muted')->default(false)->after('frame_channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('frame_channel_id');
            $table->dropColumn('frame_channel_muted');
        });
        Schema::dropIfExists('live_channels');
    }
};
