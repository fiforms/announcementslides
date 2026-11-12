<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a long-archived slide's offloaded files were removed from the bucket
 * (media:purge-archived). The row, thumbnails and posters stay; the file
 * itself is kept only in offline backups (an rclone mirror of the bucket).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slide_media', function (Blueprint $table) {
            $table->timestamp('purged_at')->nullable()->after('disk')->index();
        });
    }

    public function down(): void
    {
        Schema::table('slide_media', function (Blueprint $table) {
            $table->dropIndex(['purged_at']);
            $table->dropColumn('purged_at');
        });
    }
};
