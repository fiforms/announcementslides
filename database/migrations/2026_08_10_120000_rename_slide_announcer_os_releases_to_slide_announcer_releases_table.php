<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generalizes the OS-only releases table into one that also tracks
     * local-app releases, so both kinds get the same channel-staged
     * (stable/testing/developer) rollout mechanism instead of app updates
     * being a bare two-value config. Additive (rename + add column), not a
     * drop-and-recreate, since this table is already live outside local
     * dev — see SLIDE_ANNOUNCER.md's "New data model."
     */
    public function up(): void
    {
        Schema::rename('slide_announcer_os_releases', 'slide_announcer_releases');

        Schema::table('slide_announcer_releases', function (Blueprint $table) {
            // Plain string, not a DB enum — SlideAnnouncerRelease validates
            // 'os'|'app' at the app layer instead, same tradeoff already
            // made for is_active-per-channel uniqueness on this table.
            $table->string('kind')->default('os')->after('id');
        });

        Schema::table('slide_announcer_releases', function (Blueprint $table) {
            $table->renameColumn('bundle_disk_path', 'disk_path');
        });

        // No data backfill needed — `kind` defaults to 'os' above, and
        // every row that predates this migration is an OS bundle.
    }

    public function down(): void
    {
        Schema::table('slide_announcer_releases', function (Blueprint $table) {
            $table->renameColumn('disk_path', 'bundle_disk_path');
        });

        Schema::table('slide_announcer_releases', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::rename('slide_announcer_releases', 'slide_announcer_os_releases');
    }
};
