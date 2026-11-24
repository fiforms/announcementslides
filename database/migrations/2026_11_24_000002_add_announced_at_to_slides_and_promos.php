<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `announced_at` marks a global/nearby slide (or a promo show) whose
 * "new … available" notification has gone out, so re-running the show
 * fan-out (language or immutable edits, re-publishing) never announces it
 * twice. Everything that exists already counts as announced, so the first
 * edit after this ships doesn't notify about old slides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->timestamp('announced_at')->nullable();
        });
        Schema::table('global_show_templates', function (Blueprint $table) {
            $table->timestamp('announced_at')->nullable();
        });

        DB::table('slides')->where('status', 'published')->update(['announced_at' => now()]);
        DB::table('global_show_templates')->update(['announced_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('slides', fn (Blueprint $table) => $table->dropColumn('announced_at'));
        Schema::table('global_show_templates', fn (Blueprint $table) => $table->dropColumn('announced_at'));
    }
};
