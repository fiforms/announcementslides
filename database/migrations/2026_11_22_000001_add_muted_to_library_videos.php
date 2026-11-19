<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A library video is a silent backdrop unless an admin says it has sound.
        Schema::table('library_items', function (Blueprint $table) {
            $table->boolean('has_sound')->default(false)->after('duration_seconds');
        });

        // Players mute a slide's video when this is set (and then leave the
        // show's background music playing). Copied from the library item when
        // a slide is built; ordinary uploaded videos stay unmuted.
        Schema::table('slide_media', function (Blueprint $table) {
            $table->boolean('muted')->default(false)->after('mime_type');
        });

        // Library videos that existed before this column are silent by the new default.
        DB::table('slide_media')
            ->where('mime_type', 'like', 'video/%')
            ->where('disk_path', 'like', 'library/%')
            ->update(['muted' => true]);
    }

    public function down(): void
    {
        Schema::table('slide_media', fn (Blueprint $table) => $table->dropColumn('muted'));
        Schema::table('library_items', fn (Blueprint $table) => $table->dropColumn('has_sound'));
    }
};
