<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A show's "frame": an optional base image/video, overlay and widgets
     * drawn under/over every slide of the show. They're ordinary slide_media
     * rows (so uploads, thumbnails, the overlay editor, widget placements
     * in overlay_settings and the widget-data endpoints all work as for a
     * slide) that belong to a show instead of a slide: slide_id is null and
     * show_id is set, with media_type `show-base` or `show-overlay`.
     */
    public function up(): void
    {
        Schema::table('slide_media', function (Blueprint $table) {
            $table->foreignId('slide_id')->nullable()->change();
            $table->foreignId('show_id')->nullable()->after('slide_id')->constrained()->cascadeOnDelete();
            $table->index(['show_id', 'media_type']);
        });
    }

    public function down(): void
    {
        Schema::table('slide_media', function (Blueprint $table) {
            $table->dropConstrainedForeignId('show_id');
        });
    }
};
