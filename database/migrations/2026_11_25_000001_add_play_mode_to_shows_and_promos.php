<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `play_mode` decides how a show is played: `sequence` (the default) runs its
 * slides in order and loops; `menu` offers them as a grid to pick from, plays
 * the chosen one once and returns to the grid (a numbered video series, a
 * navigable playlist). A promo's mode lives on its template and is copied to
 * each site's show copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['shows', 'global_show_templates'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('play_mode', 16)->default('sequence');
            });
        }
    }

    public function down(): void
    {
        foreach (['shows', 'global_show_templates'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('play_mode'));
        }
    }
};
