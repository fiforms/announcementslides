<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Library audio (kind 'audio') has no thumbnail; its length is read
        // with ffprobe when it is adopted (null if that wasn't possible).
        Schema::table('library_items', function (Blueprint $table) {
            $table->unsignedInteger('duration_seconds')->nullable()->after('image_height');
        });

        // A show's background music: an ordered list of library audio items.
        // Today the UI offers a single track, which the players loop; with
        // more rows they play in `sort_order` and then repeat, so playlist
        // support needs no schema change. Deleting a library item drops it
        // from every show that used it.
        Schema::create('show_audio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('show_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_item_id')->constrained('library_items')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['show_id', 'sort_order']);
        });

        Schema::table('shows', function (Blueprint $table) {
            $table->unsignedTinyInteger('frame_audio_volume')->default(100)->after('frame_channel_muted');
        });
    }

    public function down(): void
    {
        Schema::table('shows', fn (Blueprint $table) => $table->dropColumn('frame_audio_volume'));
        Schema::dropIfExists('show_audio');
        Schema::table('library_items', fn (Blueprint $table) => $table->dropColumn('duration_seconds'));
    }
};
