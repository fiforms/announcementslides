<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces channel/is_active on slide_announcer_releases with a
     * separate tagging table. A release is now an immutable uploaded
     * build (kind, version, architecture) with one stable URL forever —
     * "which channel(s) is this current on" is a fact about a tag, not
     * the release, since the same build can be tagged into more than one
     * channel at once (e.g. promoted from developer to testing without
     * leaving developer). Untagging a release from every channel is what
     * "archived" means — no separate status column for it.
     *
     * Dropped and recreated, not altered — confirmed OK to empty and
     * recreate on the one production server this had already reached.
     */
    public function up(): void
    {
        Schema::dropIfExists('slide_announcer_releases');

        Schema::create('slide_announcer_releases', function (Blueprint $table) {
            $table->id();
            $table->string('kind'); // 'os' | 'app' — see SlideAnnouncerRelease::KINDS
            $table->string('version');
            // Free-form (arm64, armhf, x64, ...) — not a fixed list, so a
            // future architecture needs no code change, just a new value.
            $table->string('architecture');
            $table->string('disk_path');
            $table->string('sha256');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'architecture']);
        });

        Schema::create('slide_announcer_release_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slide_announcer_release_id')->constrained('slide_announcer_releases')->cascadeOnDelete();
            $table->string('channel'); // stable | testing | developer
            $table->foreignId('tagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // Lookup direction the heartbeat controller needs: "what's
            // currently tagged for (kind, architecture, channel)" — kind/
            // architecture live on the joined release, so this index
            // covers the channel half; SlideAnnouncerRelease's own
            // ['kind', 'architecture'] index above covers the rest.
            $table->index('channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slide_announcer_release_channels');
        Schema::dropIfExists('slide_announcer_releases');
    }
};
