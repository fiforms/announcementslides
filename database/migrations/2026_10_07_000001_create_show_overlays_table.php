<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A show's own widget layer, pinned above every slide of that show (so a
     * clock doesn't fade or reload on slide changes). At most one per show.
     * `source` is the overlay editor's element list; `overlay_settings` holds
     * the validated placements ({widgets: [...]}, same shape as
     * slide_media.overlay_settings) that players and the data endpoints read.
     */
    public function up(): void
    {
        Schema::create('show_overlays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('show_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('source');
            $table->json('overlay_settings');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('show_overlays');
    }
};
