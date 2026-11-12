<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage tiers: which disk each media file / release file lives on
 * (`public` locally, or an offload disk such as `r2`), plus a stored
 * full-size poster frame for videos so thumbnails and PowerPoint exports
 * never need the video itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slide_media', function (Blueprint $table) {
            $table->string('disk', 32)->default('public')->after('disk_path');
            $table->string('poster_path')->nullable()->after('thumbnail_path');
        });

        Schema::table('slide_announcer_releases', function (Blueprint $table) {
            $table->string('disk', 32)->default('public')->after('disk_path');
        });
    }

    public function down(): void
    {
        Schema::table('slide_media', function (Blueprint $table) {
            $table->dropColumn(['disk', 'poster_path']);
        });

        Schema::table('slide_announcer_releases', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
