<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the release-type axis on top of kind: a release is now
     * full|hotfix|disk_image (see SlideAnnouncerRelease::RELEASE_TYPES).
     * A hotfix additionally records required_base_version — the exact
     * prior version it must be applied on top of. Additive (not a
     * drop/recreate like the kind/channel migration before it) since
     * this table may hold real data now.
     */
    public function up(): void
    {
        Schema::table('slide_announcer_releases', function (Blueprint $table) {
            $table->string('release_type')->default('full')->after('architecture');
            $table->string('required_base_version')->nullable()->after('release_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('slide_announcer_releases', function (Blueprint $table) {
            $table->dropColumn(['release_type', 'required_base_version']);
        });
    }
};
