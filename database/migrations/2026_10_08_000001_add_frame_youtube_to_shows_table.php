<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A show's frame background can be a YouTube video or playlist instead
     * of an uploaded file (see App\Support\YoutubeSource). Stored as the
     * parsed, validated ids — {video_id?, playlist_id?, muted} — never as the
     * pasted URL. Exclusive with a `show-base` media row.
     */
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->json('frame_youtube')->nullable()->after('auto_delete_when_empty');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn('frame_youtube');
        });
    }
};
